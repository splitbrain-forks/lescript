<?php

namespace Analogic\ACME;

class Lescript
{
    /** the public API to get real certificates */
    const API_LE = 'https://acme-v01.api.letsencrypt.org';
    /** the test API for testing the client */
    const API_TEST = 'https://acme-staging.api.letsencrypt.org';
    /** default license agreement */
    const LICENSE = 'https://letsencrypt.org/documents/LE-SA-v1.1.1-August-1-2016.pdf';

    /** @var string The API to use */
    public $ca = self::API_LE;

    /** @var string the challenge type to verify domains. http-01 only currently */
    public $challenge = 'http-01';

    /** @var string the license the user agreed to */
    public $license = self::LICENSE;
    /** @var string the country code, the user is registering from */
    public $countryCode = 'CZ';
    /** @var string the country, the user is registering from */
    public $state = "Czech Republic";
    /** @var array contact details of the user (optiona). eg: array("mailto:cert-admin@example.com", "tel:+12025551212") */
    public $contact = array(); // optional

    /** @var string where to store certificates */
    protected $certificatesDir;
    /** @var string where to place challenge tokens (parent of .well-known/acme-challenge/) */
    protected $webRootDir;
    /** @var string defaults to $certificatesDir/_account/private.pem */
    protected $accountKeyPath;

    /** @var \Psr\Log\LoggerInterface */
    protected $logger;
    /** @var ClientInterface */
    protected $client;

    /**
     * Lescript constructor.
     *
     * @param string $certificatesDir path to where to store the certificates
     * @param string $webRootDir document root of the webserver (challenge tokes will be stored there)
     * @param null|\Psr\Log\LoggerInterface $logger for logging info message, echos to STDOUT by default
     * @param ClientInterface|null $client the HTTP client to use, defaults to CurlClient
     */
    public function __construct($certificatesDir, $webRootDir, $logger = null, ClientInterface $client = null)
    {
        $this->certificatesDir = $certificatesDir;
        $this->webRootDir = $webRootDir;
        $this->logger = $logger;
        $this->client = $client ? $client : new CurlClient($this->ca);
        $this->accountKeyPath = $certificatesDir . '/_account/private.pem';
    }

    /**
     * Initializes a new LetsEncrypt account and creates a new key
     *
     * Set your contact details before calling this.
     *
     * Does nothing when the key already exists, so it's safe to call before each use.
     */
    public function initAccount()
    {
        if (!is_file($this->accountKeyPath)) {
            $this->log('Starting new account registration');
            $this->generateKey(dirname($this->accountKeyPath));
            $this->postNewReg();
            $this->log('New account certificate registered');
        } else {
            $this->log('Account already registered. Continuing.');
        }
    }

    /**
     * Get new certificates for the given Domains
     *
     * When multiple domains are given, they are set as SubjectAlternativeName. If you want
     * individual certificates, call this method for each domain individually.
     *
     * @param string[] $domains the domains to sign
     * @param bool $reuseCsr should a previously generated CSR be reused?
     * @todo this method should be split
     */
    public function signDomains(array $domains, $reuseCsr = false)
    {
        $this->log('Starting certificate generation process for domains');

        $privateAccountKey = $this->readPrivateKey($this->accountKeyPath);
        $accountKeyDetails = openssl_pkey_get_details($privateAccountKey);

        // start domains authentication
        // ----------------------------

        foreach ($domains as $domain) {

            // 1. getting available authentication options
            // -------------------------------------------

            $this->log("Requesting challenge for $domain");

            $response = $this->signedRequest(
                "/acme/new-authz",
                array("resource" => "new-authz", "identifier" => array("type" => "dns", "value" => $domain))
            );

            if (empty($response['challenges'])) {
                throw new \RuntimeException("HTTP Challenge for $domain is not available. Whole response: " . json_encode($response));
            }

            $self = $this;
            $challenge = array_reduce($response['challenges'], function ($v, $w) use (&$self) {
                return $v ? $v : ($w['type'] == $self->challenge ? $w : false);
            });
            if (!$challenge) {
                throw new \RuntimeException("HTTP Challenge for $domain is not available. Whole response: " . json_encode($response));
            }

            $this->log("Got challenge token for $domain");
            $location = $this->client->getLastLocation();

            // 2. saving authentication token for web verification
            // ---------------------------------------------------

            $directory = $this->webRootDir . '/.well-known/acme-challenge';
            $tokenPath = $directory . '/' . $challenge['token'];

            if (!file_exists($directory) && !@mkdir($directory, 0755, true)) {
                throw new \RuntimeException("Couldn't create directory to expose challenge: ${tokenPath}");
            }

            $header = array(
                // need to be in precise order!
                "e" => Base64UrlSafeEncoder::encode($accountKeyDetails["rsa"]["e"]),
                "kty" => "RSA",
                "n" => Base64UrlSafeEncoder::encode($accountKeyDetails["rsa"]["n"])

            );
            $payload = $challenge['token'] . '.' . Base64UrlSafeEncoder::encode(hash('sha256', json_encode($header),
                    true));

            file_put_contents($tokenPath, $payload);
            chmod($tokenPath, 0644);

            // 3. verification process itself
            // -------------------------------

            $uri = "http://${domain}/.well-known/acme-challenge/${challenge['token']}";

            $this->log("Token for $domain saved at $tokenPath and should be available at $uri");

            // simple self check
            if ($payload !== trim(@file_get_contents($uri))) {
                throw new \RuntimeException("Please check $uri - token not available");
            }

            $this->log("Sending request to challenge");

            // send request to challenge
            $result = $this->signedRequest(
                $challenge['uri'],
                array(
                    "resource" => "challenge",
                    "type" => $this->challenge,
                    "keyAuthorization" => $payload,
                    "token" => $challenge['token']
                )
            );

            // waiting loop
            do {
                if (empty($result['status']) || $result['status'] == "invalid") {
                    throw new \RuntimeException("Verification ended with error: " . json_encode($result));
                }
                $ended = !($result['status'] === "pending");

                if (!$ended) {
                    $this->log("Verification pending, sleeping 1s");
                    sleep(1);
                }

                $result = $this->client->get($location);

            } while (!$ended);

            $this->log("Verification ended with status: ${result['status']}");
            @unlink($tokenPath);
        }

        // requesting certificate
        // ----------------------
        $domainPath = $this->getDomainPath(reset($domains));

        // generate private key for domain if not exist
        if (!is_dir($domainPath) || !is_file($domainPath . '/private.pem')) {
            $this->generateKey($domainPath);
        }

        // load domain key
        $privateDomainKey = $this->readPrivateKey($domainPath . '/private.pem');

        $this->client->getLastLinks();

        $csr = $reuseCsr && is_file($domainPath . "/last.csr") ?
            $this->getCsrContent($domainPath . "/last.csr") :
            $this->generateCSR($privateDomainKey, $domains);

        // request certificates creation
        $result = $this->signedRequest(
            "/acme/new-cert",
            array('resource' => 'new-cert', 'csr' => $csr)
        );
        if ($this->client->getLastCode() !== 201) {
            throw new \RuntimeException("Invalid response code: " . $this->client->getLastCode() . ", " . json_encode($result));
        }
        $location = $this->client->getLastLocation();

        // waiting loop
        $certificates = array();
        while (1) {
            $this->client->getLastLinks();

            $result = $this->client->get($location);

            if ($this->client->getLastCode() == 202) {

                $this->log("Certificate generation pending, sleeping 1s");
                sleep(1);

            } else {
                if ($this->client->getLastCode() == 200) {

                    $this->log("Got certificate! YAY!");
                    $certificates[] = $this->parsePemFromBody($result);

                    foreach ($this->client->getLastLinks() as $link) {
                        $this->log("Requesting chained cert at $link");
                        $result = $this->client->get($link);
                        $certificates[] = $this->parsePemFromBody($result);
                    }

                    break;
                } else {

                    throw new \RuntimeException("Can't get certificate: HTTP code " . $this->client->getLastCode());

                }
            }
        }

        if (empty($certificates)) {
            throw new \RuntimeException('No certificates generated');
        }

        $this->log("Saving fullchain.pem");
        file_put_contents($domainPath . '/fullchain.pem', implode("\n", $certificates));

        $this->log("Saving cert.pem");
        file_put_contents($domainPath . '/cert.pem', array_shift($certificates));

        $this->log("Saving chain.pem");
        file_put_contents($domainPath . "/chain.pem", implode("\n", $certificates));

        $this->log("Done !!§§!");
    }

    /**
     * Read the given private key
     *
     * @param string $path path to the private key
     * @return resource
     */
    protected function readPrivateKey($path)
    {
        if (($key = openssl_pkey_get_private('file://' . $path)) === false) {
            throw new \RuntimeException(openssl_error_string());
        }

        return $key;
    }

    /**
     * Creates a base64 PEM certificate from the given raw certificates
     *
     * @param $body
     * @return string
     */
    protected function parsePemFromBody($body)
    {
        $pem = chunk_split(base64_encode($body), 64, "\n");
        return "-----BEGIN CERTIFICATE-----\n" . $pem . "-----END CERTIFICATE-----\n";
    }

    /**
     * Construct the path where certificates for the given domain should be stored
     *
     * @param string $domain
     * @return string
     */
    protected function getDomainPath($domain)
    {
        return $this->certificatesDir . '/' . $domain . '/';
    }

    /**
     * Registers a new account at the LE servers
     *
     * @return array
     */
    protected function postNewReg()
    {
        $this->log('Sending registration to letsencrypt server');

        $data = array('resource' => 'new-reg', 'agreement' => $this->license);
        if (!$this->contact) {
            $data['contact'] = $this->contact;
        }

        return $this->signedRequest(
            '/acme/new-reg',
            $data
        );
    }

    /**
     * Generates a Certificate Request
     *
     * @param resource $privateKey the private key @see readPrivateKey()
     * @param string[] $domains list of SAN domains to request a certificate for
     * @return string
     */
    protected function generateCSR($privateKey, array $domains)
    {
        $domain = reset($domains);
        $san = implode(",", array_map(function ($dns) {
            return "DNS:" . $dns;
        }, $domains));
        $tmpConf = tmpfile();
        $tmpConfMeta = stream_get_meta_data($tmpConf);
        $tmpConfPath = $tmpConfMeta["uri"];

        // workaround to get SAN working
        fwrite($tmpConf,
            'HOME = .
RANDFILE = $ENV::HOME/.rnd
[ req ]
default_bits = 2048
default_keyfile = privkey.pem
distinguished_name = req_distinguished_name
req_extensions = v3_req
[ req_distinguished_name ]
countryName = Country Name (2 letter code)
[ v3_req ]
basicConstraints = CA:FALSE
subjectAltName = ' . $san . '
keyUsage = nonRepudiation, digitalSignature, keyEncipherment');

        $csr = openssl_csr_new(
            array(
                "CN" => $domain,
                "ST" => $this->state,
                "C" => $this->countryCode,
                "O" => "Unknown",
            ),
            $privateKey,
            array(
                "config" => $tmpConfPath,
                "digest_alg" => "sha256"
            )
        );

        if (!$csr) {
            throw new \RuntimeException("CSR couldn't be generated! " . openssl_error_string());
        }

        openssl_csr_export($csr, $csr);
        fclose($tmpConf);

        $csrPath = $this->getDomainPath($domain) . "/last.csr";
        file_put_contents($csrPath, $csr);

        return $this->getCsrContent($csrPath);
    }

    /**
     * Get the contents of the given CSR as base64 encoded REQUEST
     *
     * @param string $csrPath
     * @return string
     */
    protected function getCsrContent($csrPath)
    {
        $csr = file_get_contents($csrPath);

        preg_match('~REQUEST-----(.*)-----END~s', $csr, $matches);

        return trim(Base64UrlSafeEncoder::encode(base64_decode($matches[1])));
    }

    /**
     * Generate a new key pair
     *
     * Creates a private.pem and public.pem in the given directory
     *
     * @param string $outputDirectory
     */
    protected function generateKey($outputDirectory)
    {
        $res = openssl_pkey_new(array(
            "private_key_type" => OPENSSL_KEYTYPE_RSA,
            "private_key_bits" => 4096,
        ));

        if (!openssl_pkey_export($res, $privateKey)) {
            throw new \RuntimeException("Key export failed!");
        }

        $details = openssl_pkey_get_details($res);

        if (!is_dir($outputDirectory)) {
            @mkdir($outputDirectory, 0700, true);
        }
        if (!is_dir($outputDirectory)) {
            throw new \RuntimeException("Cant't create directory $outputDirectory");
        }

        file_put_contents($outputDirectory . '/private.pem', $privateKey);
        file_put_contents($outputDirectory . '/public.pem', $details['key']);
    }

    /**
     * Sends a signed request to the LE API
     *
     * @param string $uri
     * @param array $payload
     * @return array API result
     */
    protected function signedRequest($uri, array $payload)
    {
        $privateKey = $this->readPrivateKey($this->accountKeyPath);
        $details = openssl_pkey_get_details($privateKey);

        $header = array(
            "alg" => "RS256",
            "jwk" => array(
                "kty" => "RSA",
                "n" => Base64UrlSafeEncoder::encode($details["rsa"]["n"]),
                "e" => Base64UrlSafeEncoder::encode($details["rsa"]["e"]),
            )
        );

        $protected = $header;
        $protected["nonce"] = $this->client->getLastNonce();

        $payload64 = Base64UrlSafeEncoder::encode(str_replace('\\/', '/', json_encode($payload)));
        $protected64 = Base64UrlSafeEncoder::encode(json_encode($protected));

        openssl_sign($protected64 . '.' . $payload64, $signed, $privateKey, "SHA256");

        $signed64 = Base64UrlSafeEncoder::encode($signed);

        $data = array(
            'header' => $header,
            'protected' => $protected64,
            'payload' => $payload64,
            'signature' => $signed64
        );

        $this->log("Sending signed request to $uri");

        return $this->client->post($uri, json_encode($data));
    }

    /**
     * Logs a message with 'info' severity
     *
     * @param string $message
     */
    protected function log($message)
    {
        if ($this->logger) {
            $this->logger->info($message);
        } else {
            echo $message . "\n";
        }
    }
}
