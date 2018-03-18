<?php

namespace Sule\BankStatements\Collector\Web;

/*
 * This file is part of the Sulaeman Bank Statements package.
 *
 * (c) Sulaeman <me@sulaeman.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Sule\BankStatements\Collector\Web;

use Illuminate\Support\Collection;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\TransferStats;
use GuzzleHttp\Psr7;
use GuzzleHttp\Cookie\SetCookie;

use Carbon\Carbon;

use DOMDocument;
use DOMNodeList;
use DOMNode;

use Sule\BankStatements\Collector\Entity;

use RuntimeException;
use Sule\BankStatements\UnderMaintenanceException;
use Sule\BankStatements\LoginFailureException;
use Sule\BankStatements\RequireExtendedProcessException;

class BniMobile extends Web
{
    /**
     * Does we already logged in.
     *
     * @var bool
     */
    protected $isLoggedIn = false;

    /**
     * The effective uri.
     *
     * @var string
     */
    public $effectiveUri;

    /**
     * The logout uri.
     *
     * @var string
     */
    protected $logoutUri;

    /**
     * The logout mbparam.
     *
     * @var string
     */
    protected $mbParam;

    /**
     * The saving menu uri.
     *
     * @var string
     */
    protected $savingMenuUri;

    /**
     * The statment uri.
     *
     * @var string
     */
    protected $statementUri;

    /**
     * The initiator account.
     *
     * @var string
     */
    protected $initiatorAccount;

    /**
     * {@inheritdoc}
     */
    public function landing()
    {
        if (is_null($this->baseUri)) {
            throw new RuntimeException('No base uri defined');
        }
        
        $options = $this->getRequestOptions(true);
        $options['headers'] = array_merge($options['headers'], [
            'Referer' => $this->baseUri
        ]);
        $options['on_stats'] = function (TransferStats $stats) {
            $this->effectiveUri = $stats->getEffectiveUri();
        };

        try {
            $response = $this->client()->request('GET', $this->baseUri, $options);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                throw new RuntimeException(Psr7\str($e->getResponse()));
            } else {
                throw new RuntimeException($e->getMessage());
            }
        }

        $path = parse_url($this->effectiveUri, PHP_URL_PATH);
        $paths = explode('/', $path);
        $this->baseUri = $this->baseUri.str_replace($paths[count($paths) - 1], '', $path);
        $this->baseUri = rtrim($this->baseUri, '/');

        $statusCode = $response->getStatusCode();

        if ($statusCode == 200) {
            $body = (string) $response->getBody();
            $body = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $body);

            $dom = new DOMDocument('1.0', 'UTF-8');

            // set error level
            $internalErrors = libxml_use_internal_errors(true);
            
            $dom->recover = true;
            $dom->strictErrorChecking = false;
            $dom->loadHTML($body);

            // Restore error level
            libxml_use_internal_errors($internalErrors);

            $loginLink = $dom->getElementById('RetailUser');
            if ($loginLink instanceOf DOMNode) {
                $loginUri = $loginLink->getAttribute('href');

                return $this->nextLanding($loginUri);
            } else {
                throw new UnderMaintenanceException('The website maybe under maintenance');
            }
        }

        return $statusCode;
    }

    /**
     * The next landing page.
     *
     * @param  string  $loginUri
     * @return int
     * @throws \RuntimeException
     */
    private function nextLanding($loginUri)
    {
        $options = $this->getRequestOptions(true);
        $options['headers'] = array_merge($options['headers'], [
            'Referer' => $this->baseUri
        ]);
        $options['on_stats'] = function (TransferStats $stats) {
            $this->effectiveUri = $stats->getEffectiveUri();
        };

        try {
            $response = $this->client()->request('GET', $loginUri, $options);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                throw new RuntimeException(Psr7\str($e->getResponse()));
            } else {
                throw new RuntimeException($e->getMessage());
            }
        }

        $path = parse_url($this->effectiveUri, PHP_URL_PATH);
        $paths = explode('/', $path);
        $this->baseUri = $this->baseUri.str_replace($paths[count($paths) - 1], '', $path);
        $this->baseUri = rtrim($this->baseUri, '/');

        $statusCode = $response->getStatusCode();

        if ($statusCode == 200) {
            $body = (string) $response->getBody();
            $body = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $body);

            $dom = new DOMDocument('1.0', 'UTF-8');

            // set error level
            $internalErrors = libxml_use_internal_errors(true);
            
            $dom->recover = true;
            $dom->strictErrorChecking = false;
            $dom->loadHTML($body);

            // Restore error level
            libxml_use_internal_errors($internalErrors);

            $userIdInput = $dom->getElementById('CorpId');
            if ( ! $userIdInput instanceOf DOMNode) {
                throw new UnderMaintenanceException('The website maybe under maintenance');
            }

            $form = $dom->getElementById('form');
            if ($form instanceOf DOMNode) {
                $this->loginUri = $form->getAttribute('action');
            }

            $this->isLanded = true;
        }

        return $statusCode;
    }

    /**
     * {@inheritdoc}
     */
    public function login()
    {
        parent::login();

        sleep($this->requestDelay);

        $options = $this->getRequestOptions(true);
        $options['headers'] = array_merge($options['headers'], [
            'Referer' => $this->effectiveUri
        ]);
        $options['on_stats'] = function (TransferStats $stats) {
            $this->effectiveUri = $stats->getEffectiveUri();
        };
        $options['form_params'] = [
            'Num_Field_Err'          => 'Please enter digits only!"', 
            'Mand_Field_Err'         => '"Mandatory field is empty!"', 
            'CorpId'                 => $this->userId, 
            'PassWord'               => $this->password, 
            '__AUTHENTICATE__'       => 'Login', 
            'CancelPage'             => 'HomePage.xml', 
            'USER_TYPE'              => '1', 
            'MBLocale'               => 'bh', 
            'language'               => 'bh', 
            'AUTHENTICATION_REQUEST' => 'True', 
            '__JS_ENCRYPT_KEY__'     => '', 
            'JavaScriptEnabled'      => 'N', 
            'deviceID'               => '', 
            'machineFingerPrint'     => '', 
            'deviceType'             => '', 
            'browserType'            => '', 
            'uniqueURLStatus'        => 'disabled', 
            'imc_service_page'       => 'SignOnRetRq', 
            'Alignment'              => 'LEFT', 
            'page'                   => 'SignOnRetRq', 
            'locale'                 => 'en', 
            'PageName'               => 'HomePage', 
            'serviceType'            => 'Dynamic'
        ];

        try {
            $response = $this->client()->request('POST', $this->loginUri, $options);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                throw new RuntimeException(Psr7\str($e->getResponse()));
            } else {
                throw new RuntimeException($e->getMessage());
            }
        }

        $statusCode = $response->getStatusCode();

        if ($statusCode == 200) {
            $body = (string) $response->getBody();
            $body = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $body);
            
            $dom = new DOMDocument('1.0', 'UTF-8');

            // set error level
            $internalErrors = libxml_use_internal_errors(true);
            
            $dom->recover = true;
            $dom->strictErrorChecking = false;
            $dom->loadHTML($body);

            // Restore error level
            libxml_use_internal_errors($internalErrors);

            $isLogginFailed = false;

            $userIdInput = $dom->getElementById('CorpId');
            if ($userIdInput instanceOf DOMNode) {
                $isLogginFailed = true;
            }

            $mbparam = $dom->getElementById('mbparam');
            if ($mbparam instanceOf DOMNode) {
                $this->mbParam = $mbparam->getAttribute('value');
            } else {
                $isLogginFailed = true;
            }

            $form = $dom->getElementById('form');
            if ($form instanceOf DOMNode) {
                $this->logoutUri = $form->getAttribute('action');
            } else {
                $isLogginFailed = true;
            }

            $as = $dom->getElementsByTagName('a');
            if ($as instanceOf DOMNodeList) {
                foreach ($as as $a) {
                    if (trim($a->nodeValue) == 'REKENING') {
                        $this->savingMenuUri = $a->getAttribute('href');
                        break;
                    }
                }
            } else {
                $isLogginFailed = true;
            }

            if ($isLogginFailed) {
                throw new LoginFailureException('Failed to login, maybe you already logged in previously while not yet logged out');
            }

            $this->isLoggedIn = true;
        }

        return $statusCode;
    }

    /**
     * {@inheritdoc}
     */
    public function collect(Carbon $startDate, Carbon $endDate)
    {
        if ( ! $this->isLoggedIn) {
            throw new RuntimeException('Use ->login() method first');
        }

        if ($startDate->month != $endDate->month || $startDate->year != $endDate->year) {
            $this->logout();

            throw new RuntimeException('Unable to collect in different month / year');
        }

        if ($this->openMenu() != 200) {
            $this->logout();

            throw new RuntimeException('Unable to open the menu content');
        }

        if (is_null($this->statementUri)) {
            throw new RuntimeException('The statement page URL not found, use ->login() method first');
        }

        if ($this->openNextMenu() != 200) {
            $this->logout();

            throw new RuntimeException('Unable to open the menu content');
        }

        if (is_null($this->statementUri)) {
            throw new RuntimeException('The statement page URL not found, use ->login() method first');
        }

        if ($this->openAccountStatement() != 200) {
            $this->logout();

            throw new RuntimeException('Unable to open the menu content');
        }

        if (is_null($this->initiatorAccount)) {
            $this->logout();

            throw new RuntimeException('Unable to find the initiator account');
        }

        sleep($this->requestDelay);

        $options = $this->getRequestOptions(true);
        $options['headers'] = array_merge($options['headers'], [
            'Referer' => $this->effectiveUri
        ]);
        $options['on_stats'] = function (TransferStats $stats) {
            $this->effectiveUri = $stats->getEffectiveUri();
        };

        $options['form_params'] = [
            'Num_Field_Err'     => '"Please enter digits only!"', 
            'Mand_Field_Err'    => '"Mandatory field is empty!"', 
            'acc1'              => $this->initiatorAccount, 
            'Search_Option'     => 'Date', 
            'TxnPeriod'         => '-1', 
            'txnSrcFromDate'    => $startDate->format('d-M-Y'), 
            'txnSrcToDate'      => $endDate->format('d-M-Y'), 
            'FullStmtInqRq'     => 'Lanjut', 
            'MAIN_ACCOUNT_TYPE' => 'OPR', 
            'mbparam'           => $this->mbParam, 
            'uniqueURLStatus'   => 'disabled', 
            'imc_service_page'  => 'AccountIDSelectRq', 
            'Alignment'         => 'LEFT', 
            'page'              => 'AccountIDSelectRq', 
            'locale'            => 'bh', 
            'PageName'          => 'AccountTypeSelectRq', 
            'serviceType'       => 'Dynamic'
        ];

        try {
            $response = $this->client()->request('POST', $this->statementUri, $options);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                throw new RuntimeException(Psr7\str($e->getResponse()));
            } else {
                throw new RuntimeException($e->getMessage());
            }
        }

        $allItems = new Collection();

        if ($response->getStatusCode() == 200) {
            $body = (string) $response->getBody();
            $body = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $body);
            
            $dom = new DOMDocument('1.0', 'UTF-8');

            // set error level
            $internalErrors = libxml_use_internal_errors(true);

            $dom->recover = true;
            $dom->strictErrorChecking = false;
            $dom->loadHTML($body);

            // Restore error level
            libxml_use_internal_errors($internalErrors);

            $mbparam = $dom->getElementById('mbparam');
            if ($mbparam instanceOf DOMNode) {
                $this->mbParam = $mbparam->getAttribute('value');
            }

            $s1Table = $dom->getElementById('s1_table');
            if ( ! $s1Table instanceOf DOMNode) {
                return $allItems;
            }

            $container = $dom->getElementsByTagName('Pagination');
            if ( ! $container instanceOf DOMNode) {
                $form = $dom->getElementById('form');
                if ($form instanceOf DOMNode) {
                    $divs = $form->getElementsByTagName('div');
                    if ($divs instanceOf DOMNodeList) {
                        foreach ($divs as $div) {
                            if ($div->getAttribute('class') == 'Commondiv') {
                                $container = $div;
                                break;
                            }
                        }
                    }
                }
            }

            if ( ! $container instanceOf DOMNode) {
                return $allItems;
            }

            $items = $this->extractStatements($container);
            if ($items->isNotEmpty()) {
                $allItems = $allItems->merge($items);

                $nextData = $dom->getElementById('NextData');
                if ($nextData instanceOf DOMNode) {
                    $this->statementUri = $nextData->getAttribute('href');
                    $this->statementUri = str_replace("javascript:fnCallAJAX('", '', $this->statementUri);
                    $this->statementUri = str_replace("')", '', $this->statementUri);
                    
                    $allItems = $allItems->merge($this->collectNextPage());
                }
            }
        }

        $items = new Collection();
        if ($allItems->isNotEmpty()) {
            $allItems = $allItems->toArray();
            for ($i = (count($allItems) - 1); $i >= 0; --$i) {
                $items->push($allItems[$i]);
            }
        }

        return $items;
    }

    /**
     * Collect next page.
     *
     * @return \Collection
     * @throws \RuntimeException
     */
    private function collectNextPage()
    {
        sleep($this->requestDelay);

        $options = $this->getRequestOptions(true);
        $options['headers'] = array_merge($options['headers'], [
            'Referer' => $this->effectiveUri
        ]);
        $options['on_stats'] = function (TransferStats $stats) {
            $this->effectiveUri = $stats->getEffectiveUri();
        };

        try {
            $response = $this->client()->request('GET', $this->statementUri, $options);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                throw new RuntimeException(Psr7\str($e->getResponse()));
            } else {
                throw new RuntimeException($e->getMessage());
            }
        }

        $allItems = new Collection();
        
        if ($response->getStatusCode() == 200) {
            $body = (string) $response->getBody();
            $body = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $body);
            
            $dom = new DOMDocument('1.0', 'UTF-8');

            // set error level
            $internalErrors = libxml_use_internal_errors(true);

            $dom->recover = true;
            $dom->strictErrorChecking = false;
            $dom->loadHTML($body);
            
            // Restore error level
            libxml_use_internal_errors($internalErrors);

            $mbparam = $dom->getElementById('mbparam');
            if ($mbparam instanceOf DOMNode) {
                $this->mbParam = $mbparam->getAttribute('value');
            }

            $s1Table = $dom->getElementById('s1_table');
            if ( ! $s1Table instanceOf DOMNode) {
                return $allItems;
            }

            $container = $dom->getElementsByTagName('Pagination');
            if ( ! $container instanceOf DOMNode) {
                $form = $dom->getElementById('form');
                if ($form instanceOf DOMNode) {
                    $divs = $form->getElementsByTagName('div');
                    if ($divs instanceOf DOMNodeList) {
                        foreach ($divs as $div) {
                            if ($div->getAttribute('class') == 'Commondiv') {
                                $container = $div;
                                break;
                            }
                        }
                    }
                }
            }

            if ( ! $container instanceOf DOMNode) {
                return $allItems;
            }

            $items = $this->extractStatements($container);
            if ($items->isNotEmpty()) {
                $allItems = $allItems->merge($items);

                $nextData = $dom->getElementById('NextData');
                if ($nextData instanceOf DOMNode) {
                    $this->statementUri = $nextData->getAttribute('href');
                    $this->statementUri = str_replace("javascript:fnCallAJAX('", '', $this->statementUri);
                    $this->statementUri = str_replace("')", '', $this->statementUri);
                    
                    $allItems = $allItems->merge($this->collectNextPage());
                }
            }
        }

        return $allItems;
    }

    /**
     * Extract statements from page.
     *
     * @param  \DOMNode  $node
     * @return \Collection
     * @throws \RuntimeException
     */
    private function extractStatements(DOMNode $node)
    {
        $items = new Collection();

        $rows = $node->getElementsByTagName('table');

        if ( ! $rows instanceOf DOMNodeList) {
            throw new RuntimeException('Required list of "table" HTML tags does not found below "div" tag Pagination');
        }

        if ($rows->length == 1) {
            return $items;
        }

        $months = [];
        $month = Carbon::now();
        for ($i = 1; $i <= 12; ++$i) {
            $month->month = $i;

            $months[strtolower($month->format('M'))] = $month->month;
        }

        $data = [];

        for($i = 1; $i < $rows->length; ++$i) {
            $row = $rows->item($i);

            $rowId = (int) preg_replace('/^([a-z])([0-9])(.*)$/', "$2", $row->getAttribute('id'));
            
            if ($rowId == 1) {
                $data = [];

                $tds = $row->getElementsByTagName('td');
                if ($tds instanceOf DOMNodeList) {
                    $node = $tds->item(0);
                    if ($node instanceOf DOMNode) {
                        if ($node->nodeValue != '') {
                            $date = str_replace('Tanggal Transaksi', '', $node->nodeValue);
                            $date = Carbon::createFromFormat('d-M-Y', $date);
                            $data['date'] = $date->format('Y-m-d');
                        }
                    }
                }
            }
            
            if ($rowId == 2) {
                $tds = $row->getElementsByTagName('td');
                if ($tds instanceOf DOMNodeList) {
                    $node = $tds->item(0);
                    if ($node instanceOf DOMNode) {
                        $description = str_replace('Uraian Transaksi', '', $node->nodeValue);
                        $description = str_replace('<br>', '|', $description);
                        $description = strip_tags($description);
                        $description = trim($description);
                        $description = rtrim($description, '|');
                        $description = preg_replace('/([ ]+)\|/', '|', $description);

                        $data['description'] = $description;
                    }
                }
            }
            
            if ($rowId == 3) {
                $tds = $row->getElementsByTagName('td');
                if ($tds instanceOf DOMNodeList) {
                    $node = $tds->item(0);
                    if ($node instanceOf DOMNode) {
                        $type = str_replace('Tipe', '', $node->nodeValue);
                        $type = str_replace('.', '', $type);
                        $type = strtoupper($type);

                        $data['type'] = $type;
                    }
                }
            }

            if ($rowId == 4) {
                $tds = $row->getElementsByTagName('td');
                if ($tds instanceOf DOMNodeList) {
                    $node = $tds->item(0);
                    if ($node instanceOf DOMNode) {
                        $amount = str_replace('Nominal', '', $node->nodeValue);
                        $amount = preg_replace('/^([a-zA-Z\s]+)/', '', $amount);
                        $amount = str_replace('.', '', $amount);
                        $amount = str_replace(',', '.', $amount);

                        $data['amount'] = $amount;
                    }
                }
            }

            if ($rowId == 5) {
                $tds = $row->getElementsByTagName('td');
                if ($tds instanceOf DOMNodeList) {
                    $node = $tds->item(0);
                    if ($node instanceOf DOMNode) {
                        $balance = str_replace('Saldo', '', $node->nodeValue);
                        $balance = preg_replace('/^([a-zA-Z\s]+)/', '', $balance);
                        $balance = str_replace('.', '', $balance);
                        $balance = str_replace(',', '.', $balance);
                    }
                }

                $uuidName = serialize($this->additionalEntityParams);
                $uuidName .= '.'.trim($data['date']);
                $uuidName .= '.'.trim($data['description']);
                $uuidName .= '.'.trim($data['type']);
                $uuidName .= '.'.trim($data['amount']);
                $uuidName .= '.'.trim($balance);

                $data['unique_id'] = $this->generateIdentifier($uuidName);

                $data = array_merge($this->additionalEntityParams, $data);

                $items->push(new Entity($data));
            }
        }

        return $items;
    }

    /**
     * Do open menu.
     *
     * @return int
     * @throws \RuntimeException
     */
    private function openMenu()
    {
        sleep($this->requestDelay);

        $options = $this->getRequestOptions(true);
        $options['headers'] = array_merge($options['headers'], [
            'Referer' => $this->effectiveUri
        ]);
        $options['on_stats'] = function (TransferStats $stats) {
            $this->effectiveUri = $stats->getEffectiveUri();
        };

        try {
            $response = $this->client()->request('GET', $this->savingMenuUri, $options);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                throw new RuntimeException(Psr7\str($e->getResponse()));
            } else {
                throw new RuntimeException($e->getMessage());
            }
        }

        $statusCode = $response->getStatusCode();

        if ($statusCode == 200) {
            $body = (string) $response->getBody();
            $body = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $body);

            $dom = new DOMDocument('1.0', 'UTF-8');

            // set error level
            $internalErrors = libxml_use_internal_errors(true);
            
            $dom->recover = true;
            $dom->strictErrorChecking = false;
            $dom->loadHTML($body);

            // Restore error level
            libxml_use_internal_errors($internalErrors);

            $as = $dom->getElementsByTagName('a');
            if ($as instanceOf DOMNodeList) {
                foreach ($as as $a) {
                    if (trim($a->nodeValue) == 'MUTASI REKENING') {
                        $this->statementUri = $a->getAttribute('href');
                        break;
                    }
                }
            }

            $mbparam = $dom->getElementById('mbparam');
            if ($mbparam instanceOf DOMNode) {
                $this->mbParam = $mbparam->getAttribute('value');
            }
        }

        return $statusCode;
    }

    /**
     * Do open next menu.
     *
     * @return int
     * @throws \RuntimeException
     */
    private function openNextMenu()
    {
        sleep($this->requestDelay);

        $options = $this->getRequestOptions(true);
        $options['headers'] = array_merge($options['headers'], [
            'Referer' => $this->effectiveUri
        ]);
        $options['on_stats'] = function (TransferStats $stats) {
            $this->effectiveUri = $stats->getEffectiveUri();
        };

        try {
            $response = $this->client()->request('GET', $this->statementUri, $options);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                throw new RuntimeException(Psr7\str($e->getResponse()));
            } else {
                throw new RuntimeException($e->getMessage());
            }
        }

        $statusCode = $response->getStatusCode();

        if ($statusCode == 200) {
            $body = (string) $response->getBody();
            $body = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $body);

            $dom = new DOMDocument('1.0', 'UTF-8');

            // set error level
            $internalErrors = libxml_use_internal_errors(true);
            
            $dom->recover = true;
            $dom->strictErrorChecking = false;
            $dom->loadHTML($body);

            // Restore error level
            libxml_use_internal_errors($internalErrors);

            $form = $dom->getElementById('form');
            if ($form instanceOf DOMNode) {
                $this->statementUri = $form->getAttribute('action');
            }

            $mbparam = $dom->getElementById('mbparam');
            if ($mbparam instanceOf DOMNode) {
                $this->mbParam = $mbparam->getAttribute('value');
            }
        }

        return $statusCode;
    }

    /**
     * Do open account statement page.
     *
     * @return int
     * @throws \RuntimeException
     */
    private function openAccountStatement()
    {
        sleep($this->requestDelay);

        $options = $this->getRequestOptions(true);
        $options['headers'] = array_merge($options['headers'], [
            'Referer' => $this->effectiveUri
        ]);
        $options['on_stats'] = function (TransferStats $stats) {
            $this->effectiveUri = $stats->getEffectiveUri();
        };
        $options['form_params'] = [
            'Num_Field_Err'      => '"Please enter digits only!"', 
            'Mand_Field_Err'     => '"Mandatory field is empty!"', 
            'MAIN_ACCOUNT_TYPE'  => 'OPR', 
            'AccountIDSelectRq'  => 'Lanjut', 
            'AccountRequestType' => 'Query', 
            'mbparam'            => $this->mbParam, 
            'uniqueURLStatus'    => 'disabled', 
            'imc_service_page'   => 'AccountTypeSelectRq', 
            'Alignment'          => 'LEFT', 
            'page'               => 'AccountTypeSelectRq', 
            'locale'             => 'bh', 
            'PageName'           => 'TranHistoryRq', 
            'serviceType'        => 'Dynamic'
        ];

        try {
            $response = $this->client()->request('POST', $this->statementUri, $options);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                throw new RuntimeException(Psr7\str($e->getResponse()));
            } else {
                throw new RuntimeException($e->getMessage());
            }
        }

        $statusCode = $response->getStatusCode();

        if ($statusCode == 200) {
            $body = (string) $response->getBody();
            $body = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $body);

            $dom = new DOMDocument('1.0', 'UTF-8');

            // set error level
            $internalErrors = libxml_use_internal_errors(true);
            
            $dom->recover = true;
            $dom->strictErrorChecking = false;
            $dom->loadHTML($body);

            // Restore error level
            libxml_use_internal_errors($internalErrors);

            $form = $dom->getElementById('form');
            if ($form instanceOf DOMNode) {
                $this->statementUri = $form->getAttribute('action');
            }

            $mbparam = $dom->getElementById('mbparam');
            if ($mbparam instanceOf DOMNode) {
                $this->mbParam = $mbparam->getAttribute('value');
            }

            $inputs = $dom->getElementsByTagName('input');
            if ($inputs instanceOf DOMNodeList) {
                foreach ($inputs as $input) {
                    if ($input->getAttribute('type') == 'radio' && $input->getAttribute('name') == 'acc1') {
                        $this->initiatorAccount = $input->getAttribute('value');
                        break;
                    }
                }
            }
        }

        return $statusCode;
    }

    /**
     * {@inheritdoc}
     */
    public function logout()
    {
        if ( ! $this->isLoggedIn) {
            return false;
        }

        sleep($this->requestDelay);

        $options = $this->getRequestOptions(true);
        $options['headers'] = array_merge($options['headers'], [
            'Referer' => $this->effectiveUri
        ]);
        $options['on_stats'] = function (TransferStats $stats) {
            $this->effectiveUri = $stats->getEffectiveUri();
        };

        $options['form_params'] = [
            'Num_Field_Err'    => '"Please enter digits only!"', 
            'Mand_Field_Err'   => '"Mandatory field is empty!"', 
            'LogOut'           => 'Keluar', 
            'mbparam'          => $this->mbParam, 
            'uniqueURLStatus'  => 'disabled', 
            'imc_service_page' => 'AccountsMenuRq', 
            'Alignment'        => 'LEFT', 
            'page'             => 'AccountsMenuRq', 
            'locale'           => 'bh', 
            'PageName'         => 'AccountsUrlRq', 
            'serviceType'      => 'Dynamic'
        ];

        try {
            $response = $this->client()->request('POST', $this->logoutUri, $options);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                throw new RuntimeException(Psr7\str($e->getResponse()));
            } else {
                throw new RuntimeException($e->getMessage());
            }
        }

        $statusCode = $response->getStatusCode();

        if ($statusCode == 200) {
            $body = (string) $response->getBody();
            $body = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $body);
            
            $dom = new DOMDocument('1.0', 'UTF-8');

            // set error level
            $internalErrors = libxml_use_internal_errors(true);
            
            $dom->recover = true;
            $dom->strictErrorChecking = false;
            $dom->loadHTML($body);

            // Restore error level
            libxml_use_internal_errors($internalErrors);

            $mbparam = $dom->getElementById('mbparam');
            if ($mbparam instanceOf DOMNode) {
                $this->mbParam = $mbparam->getAttribute('value');
            }

            $form = $dom->getElementById('form');
            if ($form instanceOf DOMNode) {
                $actionUri = $form->getAttribute('action');

                $this->completeLogout($actionUri);
            }
        }

        return $statusCode;
    }

    /**
     * Do logout completly.
     *
     * @param  string $actionUri
     * @return int
     * @throws \RuntimeException
     */
    private function completeLogout($actionUri)
    {
        sleep($this->requestDelay);
        
        $options = $this->getRequestOptions(true);
        $options['headers'] = array_merge($options['headers'], [
            'Referer' => $this->effectiveUri
        ]);
        $options['form_params'] = [
            'Num_Field_Err'    => '"Please enter digits only!"', 
            'Mand_Field_Err'   => '"Mandatory field is empty!"', 
            '__LOGOUT__'       => 'Keluar', 
            'mbparam'          => $this->mbParam, 
            'uniqueURLStatus'  => 'disabled', 
            'imc_service_page' => 'SignOffUrlRq', 
            'Alignment'        => 'LEFT', 
            'page'             => 'SignOffUrlRq', 
            'locale'           => 'bh', 
            'PageName'         => 'AccountsMenuRq', 
            'serviceType'      => 'Dynamic'
        ];

        try {
            $response = $this->client()->request('POST', $actionUri, $options);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                throw new RuntimeException(Psr7\str($e->getResponse()));
            } else {
                throw new RuntimeException($e->getMessage());
            }
        }

        $statusCode = $response->getStatusCode();

        if ($statusCode == 200) {
            $this->isLoggedIn = false;
        }

        return $statusCode;
    }
}
