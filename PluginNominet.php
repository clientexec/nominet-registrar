<?php

require_once 'modules/admin/models/RegistrarPlugin.php';
require_once 'nominet.php';

class PluginNominet extends RegistrarPlugin
{
    public $features = [
        'nameSuggest' => false,
        'importDomains' => false,
        'importPrices' => false,
    ];

    private $api;

    private function setup($params)
    {
        $this->api = new Nominet(
            $params['Username'],
            $params['Password'],
            $params['Use Testbed?']
        );
    }

    public function getVariables()
    {
        $variables = [
            lang('Plugin Name') => [
                'type' => 'hidden',
                'description' => lang('How CE sees this plugin (not to be confused with the Signup Name)'),
                'value' => lang('Nominet')
            ],
            lang('Use Testbed?') => [
                'type' => 'yesno',
                'description' => lang('Select Yes if you wish to use Nominet\'s testbed environment.'),
                'value' => 0
            ],
            lang('Username') => [
                'type' => 'text',
                'description' => lang('Enter your username for your Nominet account.'),
                'value' => ''
            ],
            lang('Password') => [
                'type' => 'text',
                'description' => lang('Enter the password for your Nominet account.'),
                'value' => '',
            ],
            lang('Default NS1') => [
                'type' => 'text',
                'description' => lang('Enter the default name server for the domain if no hosting package.'),
                'value' => '',
            ],
            lang('Default NS2') => [
                'type' => 'text',
                'description' => lang('Enter the default name server for the domain if no hosting package.'),
                'value' => '',
            ],
            lang('Supported Features') => [
                'type' => 'label',
                'description' => '* ' . lang('TLD Lookup') . '<br>* ' . lang('Domain Registration') . ' <br>* ' . lang('Get / Set Nameserver Records') . ' <br>* ' . lang('Get / Set Contact Information') . ' <br>* ' . lang('Automatically Renew Domain'),
                'value' => ''
            ],
            lang('Actions') => [
                'type' => 'hidden',
                'description' => lang('Current actions that are active for this plugin (when a domain isn\'t registered)'),
                'value' => 'Register'
            ],
            lang('Registered Actions') => [
                'type' => 'hidden',
                'description' => lang('Current actions that are active for this plugin (when a domain is registered)'),
                'value' => 'Renew (Renew Domain),Cancel',
            ],
            lang('Registered Actions For Customer') => [
                'type' => 'hidden',
                'description' => lang('Current actions that are active for this plugin (when a domain is registered)'),
                'value' => '',
            ]
        ];

        return $variables;
    }

    public function doRegister($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $orderid = $this->registerDomain($this->buildRegisterParams($userPackage, $params));
        $userPackage->setCustomField("Registrar Order Id", $userPackage->getCustomField("Registrar") . '-' . $orderid);
        return $userPackage->getCustomField('Domain Name') . ' has been registered.';
    }

    public function doRenew($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $orderid = $this->renewDomain($this->buildRenewParams($userPackage, $params));
        $userPackage->setCustomField("Registrar Order Id", $userPackage->getCustomField("Registrar") . '-' . $orderid);
        return $userPackage->getCustomField('Domain Name') . ' has been renewed.';
    }

    public function checkDomain($params)
    {

        $this->setup($params);
        $this->api->connect();
        $this->api->login();
        $domain = $params['sld'] . '.' . $params['tld'];

        $xml = '
            <command>
    <check>
      <domain:check
       xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
        <domain:name>' . $domain . ' </domain:name>
      </domain:check>
    </check>
  </command>
</epp>';
        $response = $this->api->call($xml);
        $data = $response['epp']['#']['response'][0]['#']['resData'];
        $status = $data[0]['#']['domain:chkData'][0]['#']['domain:cd'][0]['#']['domain:name'][0]['@']['avail'];

        if ($status == 1) {
            $status = 0;
        } else {
            $status = 1;
        }

        $domains[] = [
            'tld' => $params['tld'],
            'domain' => $params['sld'],
            'status' => $status
        ];

        return [
            'result' => $domains
        ];
    }

    public function renewDomain($params)
    {
        $this->setup($params);
        $this->api->connect();
        $this->api->login();

        $domainName = $params['sld'] . '.' . $params['tld'];

        $info = $this->getGeneralInfo($params);
        $info['expiration'] = explode("T", $info['expiration']);
        $xml = '
<command>
    <renew>
        <domain:renew xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"  xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0  domain-1.0.xsd\">
            <domain:name>' .$this->escape($domainName) . '</domain:name>
            <domain:curExpDate>' . $this->escape($info['expiration'][0]) . '</domain:curExpDate>
            <domain:period unit="y">' .$this->escape($params['NumYears']) . '</domain:period>
        </domain:renew>
    </renew>
</command>
</epp>';

        $result = $this->api->call($xml);
        if ($this->api->isError()) {
            throw new CE_Exception($this->api->getErrorMessage());
        }
    }

    private function createContact($params)
    {
        $regName = $params['RegistrantFirstName'] . ' ' . $params['RegistrantLastName'];
        if (!empty($params['RegistrantOrganizationName'])) {
            $regOrgName = "<contact:org>" . $this->escape($params['RegistrantOrganizationName']) . "</contact:org>";
        }
        $phone = $this->validatePhone($params['RegistrantPhone'], $params['RegistrantCountry']);


        $xml = '
<command>
    <create>
        <contact:create xmlns:contact="urn:ietf:params:xml:ns:contact-1.0"     xsi:schemaLocation="urn:ietf:params:xml:ns:contact-1.0 contact-1.0.xsd">
            <contact:id>Clientexec' . $this->escape($params['userPackageId']) . rand(1, 10000) . '</contact:id>
            <contact:postalInfo type="loc">
                <contact:name>' . $this->escape($$pho) . '</contact:name>
                 ' . $regOrgName . '
                  <contact:addr>
                    <contact:street>' . $this->escape($params['RegistrantAddress1']) . '</contact:street>
                    <contact:city>' . $this->escape($params['RegistrantCity']) . '</contact:city>
                    <contact:sp>' . $this->escape($params['RegistrantStateProvince']) . '</contact:sp>
                    <contact:pc>' . $this->escape($params['RegistrantPostalCode']) . '</contact:pc>
                    <contact:cc>' . $this->escape($params['RegistrantCountry']) . '</contact:cc>
                </contact:addr>
            </contact:postalInfo>
            <contact:voice>' . $this->escape($phone) . '</contact:voice>
            <contact:email>' . $this->escape($params['RegistrantEmailAddress']) . '</contact:email>
            <contact:authInfo>
                <contact:pw></contact:pw>
            </contact:authInfo>
        </contact:create>
    </create>
    <extension>
        <contact-ext:create xmlns:contact-ext="http://www.nominet.org.uk/epp/xml/contact-nom-ext-1.0" xsi:schemaLocation="http://www.nominet.org.uk/epp/xml/contact-nom-ext-1.0 contact-nom-ext-1.0.xsd">';

        if (!empty($params['ExtendedAttributes']['registered_for'])) {
            $xml .= "
            <contact-ext:trad-name>" . $this->escape($params['ExtendedAttributes']['registered_for']) . "</contact-ext:trad-name>\n";
        }
        $xml .= "<contact-ext:type>" . $this->escape($params['ExtendedAttributes']['uk_legal_type']) . "</contact-ext:type>\n";
        if (!empty($params['ExtendedAttributes']['uk_reg_co_no'])) {
            $xml .= "<contact-ext:co-no>" . $this->escape($params['ExtendedAttributes']['uk_reg_co_no']) . "</contact-ext:co-no>\n";
        }
        $xml .= '
        </contact-ext:create>
    </extension>
    </command>
    </epp>';

        $response = $this->api->call($xml);
        if ($this->api->isError()) {
            throw new CE_Exception($this->api->getErrorMessage());
        }
        $data = $response['epp']['#']['response'][0]['#']['resData'];
        return $data[0]['#']['contact:creData'][0]['#']['contact:id'][0]['#'];
    }

    public function registerDomain($params)
    {
        $this->setup($params);
        $this->api->connect();
        $this->api->login();

        $contactId = $this->createContact($params);

        if (isset($params['NS1'])) {
            for ($i = 1; $i <= 12; $i++) {
                if (isset($params["NS$i"]['hostname'])) {
                    $nameServers[] = $params["NS$i"]['hostname'];
                }
            }
        }
        if (count($nameServers) == 0) {
            if (!empty($params['Default NS1'])) {
                $nameServers[] = $params['Default NS1'];
            }
            if (!empty($params['Default NS2'])) {
                $nameServers[] = $params['Default NS2'];
            }
        }
        $ns = "\n<domain:ns>";
        foreach ($nameServers as $nameserver) {
            $ns .= "\n\t<domain:hostObj>" . $this->escape($nameserver) . "</domain:hostObj>";
        }
        $ns .= "\n</domain:ns>\n";

        $xml = '
<command>
    <create>
        <domain:create xmlns:domain="urn:ietf:params:xml:ns:domain-1.0" xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0  domain-1.0.xsd">
            <domain:name>' . $this->escape($params['sld'] . '.' . $params['tld']) . '</domain:name>
            <domain:period unit="y">' . $params['NumYears'] . "</domain:period>" .
            $ns . "
            <domain:registrant>" . $this->escape($contactId) . "</domain:registrant>
            <domain:authInfo>
                <domain:pw></domain:pw>
            </domain:authInfo>
        </domain:create>
    </create>
</command>
</epp>";

        $response = $this->api->call($xml);

        if ($this->api->isError()) {
            throw new CE_Exception($this->api->getErrorMessage());
        }
    }

    private function validatePhone($phone, $country)
    {
        // strip all non numerical values
        $phone = preg_replace('/[^\d]/', '', $phone);

        if ($phone == '') {
            return $phone;
        }

        $query = "SELECT phone_code FROM country WHERE iso=? and phone_code != ''";
        $result = $this->db->query($query, $country);
        if (!$row = $result->fetch()) {
            return $phone;
        }

        // check if code is already there
        $code = $row['phone_code'];
        $phone = preg_replace("/^($code)(\\d+)/", '+\1.\2', $phone);
        if ($phone[0] == '+') {
            return $phone;
        }

        // if not, prepend it
        return "+$code.$phone";
    }

    public function getContactInformation($params)
    {
        $domain = $params['sld'] . '.' . $params['tld'];
        $this->setup($params);
        $this->api->connect();
        $this->api->login();

        $xml = '
<command>
    <info>
        <domain:info xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
            <domain:name hosts="all">' .
                $this->escape($domain) . "
            </domain:name>
        </domain:info>
    </info>
</command>
</epp>";
        $response = $this->api->call($xml);
        $contactId = $response['epp']['#']['response'][0]['#']['resData'][0]['#']['domain:infData'][0]['#']['domain:registrant'][0]['#'];


        $xml = '
<command>
    <info>
        <contact:info xmlns:contact="urn:ietf:params:xml:ns:contact-1.0"                          xsi:schemaLocation="urn:ietf:params:xml:ns:contact-1.0                          contact-1.0.xsd">
            <contact:id>' . $this->escape($contactId) . '</contact:id>
        </contact:info>
    </info>
</command>
</epp>';
        $response = $this->api->call($xml);


        $contactDetails = $response['epp']['#']['response'][0]['#']['resData'][0]['#']['contact:infData'][0]['#'];
        $responsePostalInfo = $contactDetails['contact:postalInfo'][0]['#'];

        $type = 'Registrant';
        $info = [];
        $addr = $responsePostalInfo['contact:addr'][0]['#'];
        $info[$type]['OrganizationName'] = [
            $this->user->lang('Organization'),
            $responsePostalInfo['contact:org'][0]['#']
        ];
        $info[$type]['ContactName'] = [
            $this->user->lang('Contact Name'),
            $responsePostalInfo['contact:name'][0]['#']
        ];

        $info[$type]['Address1'] = [
            $this->user->lang('Address').' 1',
            $addr['contact:street'][0]['#']
        ];

        $info[$type]['City'] = [
            $this->user->lang('City'),
            $addr['contact:city'][0]['#']
        ];
        $info[$type]['StateProv'] = [
            $this->user->lang('Province').'/'.$this->user->lang('State'),
            $addr['contact:sp'][0]['#']
        ];
        $info[$type]['Country']  = [
            $this->user->lang('Country'),
            $addr['contact:cc'][0]['#']
        ];
        $info[$type]['PostalCode']  = [
            $this->user->lang('Postal Code'),
            $addr['contact:pc'][0]['#']
        ];
        $info[$type]['EmailAddress'] = [
            $this->user->lang('E-mail'),
            $contactDetails['contact:email'][0]['#']
        ];
        $info[$type]['Phone'] = [
            $this->user->lang('Phone'),
            $contactDetails['contact:voice'][0]['#']
        ];

        return $info;
    }

    public function setContactInformation($params)
    {
        $domain = $params['sld'] . '.' . $params['tld'];
        $this->setup($params);
        $this->api->connect();
        $this->api->login();

        $xml = '
<command>
    <info>
        <domain:info xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
            <domain:name hosts="all">' .
                $this->escape($domain) . "
            </domain:name>
        </domain:info>
    </info>
</command>
</epp>";
        $response = $this->api->call($xml);
        $contactId = $response['epp']['#']['response'][0]['#']['resData'][0]['#']['domain:infData'][0]['#']['domain:registrant'][0]['#'];

        $xml = '
<command>
    <update>
        <contact:update xmlns:contact="urn:ietf:params:xml:ns:contact-1.0"                          xsi:schemaLocation="urn:ietf:params:xml:ns:contact-1.0                          contact-1.0.xsd">
            <contact:id>' . $contactId . '</contact:id>
            <contact:chg>
                <contact:postalInfo type="loc">
                <contact:name>' . $this->escape($params['Registrant_ContactName']) . '</contact:name>
                 ' . $regOrgName . '
                    <contact:addr>
                    <contact:street>' . $this->escape($params['Registrant_Address1']) . '</contact:street>
                    <contact:city>' . $this->escape($params['Registrant_City']) . '</contact:city>
                    <contact:sp>' . $this->escape($params['Registrant_StateProv']) . '</contact:sp>
                    <contact:pc>' . $this->escape($params['Registrant_PostalCode']) . '</contact:pc>
                    <contact:cc>' . $this->escape($params['Registrant_Country']) . '</contact:cc>
                    </contact:addr>
                </contact:postalInfo>
                <contact:voice>' . $this->escape($this->validatePhone($params['Registrant_Phone'], $params['Registrant_Country'])) . '</contact:voice>
                <contact:email>' . $this->escape($params['Registrant_EmailAddress']) . '</contact:email>
            </contact:chg>
        </contact:update>
    </update>
</command>
</epp>';


        $result = $this->api->call($xml);
        if ($this->api->isError()) {
            throw new CE_Exception($this->api->getErrorMessage());
        }
    }

    public function getNameServers($params)
    {
        $domain = $params['sld'] . '.' . $params['tld'];
        $this->setup($params);
        $this->api->connect();
        $this->api->login();

        $xml = '
<command>
    <info>
        <domain:info xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
            <domain:name hosts="all">' .
                $this->escape($domain) . "
            </domain:name>
        </domain:info>
    </info>
</command>
</epp>";
        $response = $this->api->call($xml);
        $response = $response['epp']['#']['response'][0]['#']['resData'][0]['#']['domain:infData'][0]['#']['domain:ns'][0]['#']['domain:hostObj'];

        $data = [];
        foreach ($response as $ns) {
            $data[] = $ns['#'];
        }
        return $data;
    }

    public function setNameServers($params)
    {
        $domain = $params['sld'] . '.' . $params['tld'];

        $this->setup($params);
        $this->api->connect();
        $this->api->login();

        $currentNameServers = $this->getNameServers($params);
        if (count($currentNameServers) > 0) {
            $remove = '
        <domain:rem>
            <domain:ns>';
            foreach ($currentNameServers as $ns) {
                $remove .= '<domain:hostObj>' . $this->escape($ns) . '</domain:hostObj>';
            }
            $remove .= '
            </domain:ns>
        </domain:rem>';
        }

        foreach ($params['ns'] as $value) {
            $nameServers[] = $value;
        }


        if (count($nameServers) == 0) {
            if (!empty($params['Default NS1'])) {
                $nameServers[] = $params['Default NS1'];
            }
            if (!empty($params['Default NS2'])) {
                $nameServers[] = $params['Default NS2'];
            }
        }
        $ns = "\n<domain:ns>";
        foreach ($nameServers as $nameserver) {
            $ns .= "\n\t<domain:hostObj>" . $this->escape($nameserver) . "</domain:hostObj>";
        }
        $ns .= "\n</domain:ns>\n";

        $xml = '
<command>
    <update>
        <domain:update xmlns:domain="urn:ietf:params:xml:ns:domain-1.0" xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0domain-1.0.xsd">
            <domain:name>' .
                $this->escape($domain) .
            '</domain:name>
            <domain:add>' .
            $ns .
            '</domain:add>' .
            $remove . '
        </domain:update>
    </update>
</command>
</epp>';

        $this->createNameServers($nameServers);
        $result = $this->api->call($xml);
    }

    public function getGeneralInfo($params)
    {
        $domain = $params['sld'] . '.' . $params['tld'];

        $this->setup($params);
        $this->api->connect();
        $this->api->login();

        $xml = '
        <command>
        <info>
        <domain:info xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
            <domain:name hosts="all">' . $this->escape($domain) . "</domain:name>
        </domain:info>
    </info>
</command>
</epp>";
        $response = $this->api->call($xml);
        $response = $response['epp']['#']['response'][0]['#']['resData'];
        $response = $response[0]['#']['domain:infData'][0]['#'];

        $data = [];
        $data['id'] = $response['domain:roid'][0]['#'];
        $data['domain'] = $domain;
        $data['expiration'] = $response['domain:exDate'][0]['#'];
        $data['registrationstatus'] = 'N/A';
        $data['purchasestatus'] = 'N/A';
        // $data['autorenew'] = false;

        return $data;
    }

    public function escape($string)
    {
        return htmlspecialchars($string);
    }

    public function createNameServers($ns = [])
    {
        foreach ($ns as $namserver) {
            $xml = '
<command>
    <create>
        <host:create xmlns:host="urn:ietf:params:xml:ns:host-1.0" xsi:schemaLocation="urn:ietf:params:xml:ns:host-1.0 host-1.0.xsd">
            <host:name>' .
                $this->escape($namserver) . '
            </host:name>
        </host:create>
    </create>
</command>
</epp>';
            $this->api->call($xml);
        }
    }

    public function setAutorenew($params)
    {
        throw new MethodNotImplemented();
    }

    public function getRegistrarLock($params)
    {
        throw new MethodNotImplemented();
    }

    public function setRegistrarLock($params)
    {
        throw new MethodNotImplemented();
    }

    public function sendTransferKey($params)
    {
        throw new MethodNotImplemented();
    }

    public function getDNS()
    {
        throw new MethodNotImplemented();
    }
}
