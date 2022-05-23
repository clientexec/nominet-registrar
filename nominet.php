<?php

require_once dirname(__FILE__) . '/../../../library/CE/XmlFunctions.php';

class Nominet
{

    private $username = '';
    private $password = '';
    private $useTestBed = false;
    private $host = 'epp.nominet.org.uk';
    private $testHost = 'testbed-epp.nominet.org.uk';
    private $port = 700;
    private $timeout = 5;
    private $socket = null;
    private $response = '';
    private $rawResponse = '';

    public function __construct($username, $password, $useTestBed = false)
    {
        $this->username = $username;
        $this->password = $password;
        $this->useTestBed = $useTestBed;
    }

    public function connect()
    {
        if ($this->useTestBed == true) {
            $host = $this->testHost;
        } else {
            $host = $this->host;
        }
        $host =  "tls://{$host}:{$this->port}";
        $context = stream_context_create([
            "ssl" =>
                [
                    "crypto_method" => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
                ]
            ]);
        if (!($this->socket = @stream_socket_client($host, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context))) {
            throw new Exception("Could not connect to: {$host}. {$errstr} ({$errno})", EXCEPTION_CODE_CONNECTION_ISSUE);
        }

        if (feof($this->socket)) {
            throw new Exception('Connection closed by remote server', EXCEPTION_CODE_CONNECTION_ISSUE);
        }

        $r = fread($this->socket, 4);
        if (empty($r)) {
            throw new Exception('Connection closed by remote server', EXCEPTION_CODE_CONNECTION_ISSUE);
        }
        $unpack = unpack('N', $r);
        $length = $unpack[1];
        if ($length < 5) {
            throw new Exception('Bad frame length', EXCEPTION_CODE_CONNECTION_ISSUE);
        }

        $answer = fread($this->socket, $length - 4);
        $this->processResponse($answer);
        return true;
    }

    public function login()
    {
         $xml = "
            <command>
                <login>
                    <clID>{$this->username}</clID>
                    <pw>{$this->password}</pw>
                    <options>
                        <version>1.0</version>
                        <lang>en</lang>
                    </options>
                    <svcs>
                        <objURI>urn:ietf:params:xml:ns:domain-1.0</objURI>
                        <objURI>urn:ietf:params:xml:ns:contact-1.0</objURI>
                        <objURI>urn:ietf:params:xml:ns:host-1.0</objURI>
                        <svcExtension>
                            <extURI>
                                http://www.nominet.org.uk/epp/xml/contact-nom-ext-1.0
                            </extURI>
                            <extURI>
                                http://www.nominet.org.uk/epp/xml/domain-nom-ext-1.0
                            </extURI>
                            <extURI>
                                http://www.nominet.org.uk/epp/xml/std-release-1.0
                            </extURI>
                        </svcExtension>
                    </svcs>
                </login>
            </command>
        </epp>";
        $response = $this->call($xml);
        if ($response) {
            if ($this->isError($response)) {
                throw new Exception('Login Failed', EXCEPTION_CODE_CONNECTION_ISSUE);
            } else {
                return true;
            }
        }
        return false;
    }

    public function call($xml)
    {
        $xml = '
        <?xml version="1.0" encoding="UTF-8"?>
            <epp xmlns="urn:ietf:params:xml:ns:epp-1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">' . $xml;

        fwrite($this->socket, pack("N", strlen($xml) + 4) . $xml);
        if (feof($this->socket)) {
            throw new Exception('Connection closed by remote server', EXCEPTION_CODE_CONNECTION_ISSUE);
        }

        $r = fread($this->socket, 4);
        if (empty($r)) {
            throw new Exception('Connection closed by remote server', EXCEPTION_CODE_CONNECTION_ISSUE);
        }

        $unpack = unpack("N", $r);
        $length = $unpack[1];
        if ($length < 5) {
            throw new Exception('Got a bad frame header length from server', EXCEPTION_CODE_CONNECTION_ISSUE);
        } else {
            $answer = fread($this->socket, $length - 4);
            CE_Lib::log(4, $answer);
            return $this->processResponse($answer);
        }
    }

    public function processResponse($response)
    {
        $this->rawResponse = $response;
        $this->response = XmlFunctions::xmlize($response);
        return $this->response;
    }


    public function getRawResponse()
    {
        return $this->rawResponse;
    }


    public function getResponse()
    {
        return $this->response;
    }

    public function isError()
    {
        return $this->getResponseCode() < 2000 ? false : true;
    }

    public function getResponseCode()
    {
        $pattern = "<result code=\"(\\d+)\">";
        preg_match($pattern, $this->getRawResponse(), $matches);
        $code = isset($matches[1]) ? (int) $matches[1] : 0;
        return $code;
    }

    public function close()
    {
        fclose($this->socket);
    }

    public function getErrorMessage()
    {
        $response = $this->getResponse();
        if (isset($response['epp']['#']['response'][0]['#']['result'][0]['#']['extValue'][0]['#']['reason'][0]['#'])) {
            return $response['epp']['#']['response'][0]['#']['result'][0]['#']['extValue'][0]['#']['reason'][0]['#'];
        }

        if (isset($response['epp']['#']['response'][0]['#']['result'][0]['#']['msg'][0]['#'])) {
            return $response['epp']['#']['response'][0]['#']['result'][0]['#']['msg'][0]['#'];
        }

        return 'Unknown Error';
    }
}
