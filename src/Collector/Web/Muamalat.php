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

use Carbon\Carbon;

use DOMDocument;
use DOMNodeList;
use DOMNode;

use Sule\BankStatements\Collector\Entity;

use RuntimeException;
use Sule\BankStatements\LoginFailureException;

class Muamalat extends Web
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
     * The login salt.
     *
     * @var string
     */
    public $loginSalt;

    /**
     * The account statement form data.
     *
     * @var string
     */
    public $accountStatementFormdata;

    /**
     * The first account ID.
     *
     * @var string
     */
    public $accountId;

    /**
     * {@inheritdoc}
     */
    public function landing()
    {
        if (is_null($this->baseUri)) {
            throw new RuntimeException('No base uri defined');
        }
        
        $options = $this->getRequestOptions();
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

        $statusCode = $response->getStatusCode();

        if ($statusCode == 200) {
            $body = (string) $response->getBody();

            preg_match("/var\ssalt='(.*?)';/", $body, $matches);
            if (isset($matches[1])) {
                $this->loginSalt = $matches[1];
            }

            $body = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $body);

            $dom = new DOMDocument('1.0', 'UTF-8');

            // set error level
            $internalErrors = libxml_use_internal_errors(true);
            $dom->recover = true;
            $dom->strictErrorChecking = false;
            $dom->loadHTML($body);

            // Restore error level
            libxml_use_internal_errors($internalErrors);

            $forms = $dom->getElementsByTagName('form');

            if ($forms instanceOf DOMNodeList) {
                foreach ($forms as $form) {
                    if ($form->getAttribute('name') == 'formLogin') {
                        $this->loginUri = $this->baseUri.'/'.ltrim($form->getAttribute('action'), '/');
                        break;
                    }
                }
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

        if (is_null($this->loginSalt)) {
            throw new RuntimeException('No Login Salt defined');
        }

        sleep($this->requestDelay);

        $options = $this->getRequestOptions();
        $options['allow_redirects'] = false;
        $options['headers'] = array_merge($options['headers'], [
            'Referer'         => $this->effectiveUri,
            'Content-Type'    => 'application/x-www-form-urlencoded'
        ]);
        $options['on_stats'] = function (TransferStats $stats) {
            $this->effectiveUri = $stats->getEffectiveUri();
        };
        $options['body'] = http_build_query([
            'j_username'       => strtoupper($this->userId), 
            'j_password'       => hash('sha256', hash('sha256', $this->password.strtoupper($this->userId)).$this->loginSalt), 
            'j_plain_username' => $this->userId, 
            'j_plain_password' => '',
            'x'                => 0,
            'y'                => 0
        ]);

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

        if ($statusCode == 302) {
            return $this->openHomeredirect();
        }

        return $statusCode;
    }

    /**
     * Follow the login redirection.
     *
     * @return int
     * @throws \RuntimeException
     */
    private function openHomeredirect()
    {
        $options = $this->getRequestOptions();
        $options['allow_redirects'] = false;
        $options['headers'] = array_merge($options['headers'], [
            'Referer'         => $this->effectiveUri
        ]);
        $options['on_stats'] = function (TransferStats $stats) {
            $this->effectiveUri = $stats->getEffectiveUri();
        };

        $url = $this->baseUri.'/ib-app/homeredirector';

        try {
            $response = $this->client()->request('GET', $url, $options);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                throw new RuntimeException(Psr7\str($e->getResponse()));
            } else {
                throw new RuntimeException($e->getMessage());
            }
        }

        $statusCode = $response->getStatusCode();

        if ($statusCode == 302) {
            return $this->openHomepage();
        }

        return $statusCode;
    }

    /**
     * Follow the login redirection.
     *
     * @return int
     * @throws \RuntimeException
     */
    private function openHomepage()
    {
        $options = $this->getRequestOptions();
        $options['headers'] = array_merge($options['headers'], [
            'Referer'         => $this->effectiveUri
        ]);
        $options['on_stats'] = function (TransferStats $stats) {
            $this->effectiveUri = $stats->getEffectiveUri();
        };

        $url = $this->baseUri.'/ib-app/en/homepage';

        try {
            $response = $this->client()->request('GET', $url, $options);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                throw new RuntimeException(Psr7\str($e->getResponse()));
            } else {
                throw new RuntimeException($e->getMessage());
            }
        }

        $statusCode = $response->getStatusCode();

        if ($statusCode == 200) {
            $this->openBlankIframe();
            $this->openHomeWelcomeIframe();
        } else {
            throw new LoginFailureException('Failed to login');
        }

        return $statusCode;
    }

    /**
     * Do open blank iframe.
     *
     * @return int
     * @throws \RuntimeException
     */
    private function openBlankIframe()
    {
        $options = $this->getRequestOptions();
        $options['headers'] = array_merge($options['headers'], [
            'Referer' => $this->effectiveUri
        ]);
        $options['on_stats'] = function (TransferStats $stats) {
            $this->effectiveUri = $stats->getEffectiveUri();
        };

        $url = $this->baseUri.'/sib/blank.htm';

        try {
            $response = $this->client()->request('GET', $url, $options);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                throw new RuntimeException(Psr7\str($e->getResponse()));
            } else {
                throw new RuntimeException($e->getMessage());
            }
        }

        return $response->getStatusCode();
    }

    /**
     * Do open homewelcome iframe.
     *
     * @return int
     * @throws \RuntimeException
     */
    private function openHomeWelcomeIframe()
    {
        $options = $this->getRequestOptions();
        $options['headers'] = array_merge($options['headers'], [
            'Referer' => $this->effectiveUri
        ]);
        $options['on_stats'] = function (TransferStats $stats) {
            $this->effectiveUri = $stats->getEffectiveUri();
        };

        $url = $this->baseUri.'/ib-app/en/homewelcome';

        try {
            $response = $this->client()->request('GET', $url, $options);
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

            preg_match("/Account\sinformation/", $body, $matches);
            if ( ! isset($matches[0])) {
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

        if ($this->openAccountStatement() != 200) {
            $this->logout();

            throw new RuntimeException('Unable to open the menu content');
        }

        if (is_null($this->accountStatementFormdata)) {
            $this->logout();

            throw new RuntimeException('Unable to find the account statement formdata value');
        }

        if (is_null($this->accountId)) {
            $this->logout();

            throw new RuntimeException('Unable to find the account first account id value');
        }

        sleep($this->requestDelay);

        $options = $this->getRequestOptions();
        $options['allow_redirects'] = false;
        $options['headers'] = array_merge($options['headers'], [
            'Referer'      => $this->effectiveUri,
            'Content-Type' => 'application/x-www-form-urlencoded'
        ]);
        $options['on_stats'] = function (TransferStats $stats) {
            $this->effectiveUri = $stats->getEffectiveUri();
        };

        $options['body'] = http_build_query([
            't:formdata'    => $this->accountStatementFormdata,
            'radiogroup'    => 2,
            'dateFrom'      => $startDate->day.'/'.$startDate->month.'/'.$startDate->year,
            'dateTo'        => $endDate->day.'/'.$endDate->month.'/'.$endDate->year,
            'accountSelect' => $this->accountId
        ]);

        $url = $this->baseUri.'/ib-app/en/mi11300a.form1';

        try {
            $response = $this->client()->request('POST', $url, $options);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                throw new RuntimeException(Psr7\str($e->getResponse()));
            } else {
                throw new RuntimeException($e->getMessage());
            }
        }

        $items = new Collection();

        if ($response->getStatusCode() == 302) {
            return $this->continueCollect($items);
        }

        return $items;
    }

    /**
     * Continue collecting.
     *
     * @param  \Carbon\Carbon  $date
     * @param  \Illuminate\Support\Collection $items
     *
     * @return \Illuminate\Support\Collection
     * @throws \RuntimeException
     */
    private function continueCollect(Collection $items)
    {
        $options = $this->getRequestOptions();
        $options['headers'] = array_merge($options['headers'], [
            'Referer'      => $this->effectiveUri
        ]);
        $options['on_stats'] = function (TransferStats $stats) {
            $this->effectiveUri = $stats->getEffectiveUri();
        };

        $url = $this->baseUri.'/ib-app/en/cug02202a';

        try {
            $response = $this->client()->request('GET', $url, $options);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                throw new RuntimeException(Psr7\str($e->getResponse()));
            } else {
                throw new RuntimeException($e->getMessage());
            }
        }

        if ($response->getStatusCode() == 200) {
            $body = (string) $response->getBody();
            $body = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $body);
            
            $items = $this->extractStatements($body);
        }

        return $items;
    }

    /**
     * Extract statements from page.
     *
     * @param  string          $html
     * @return \Illuminate\Support\Collection
     * @throws \RuntimeException
     */
    private function extractStatements($html)
    {
        $dom = new DOMDocument('1.0', 'UTF-8');

        // set error level
        $internalErrors = libxml_use_internal_errors(true);
        
        $dom->recover = true;
        $dom->strictErrorChecking = false;
        $dom->loadHTML($html);

        // Restore error level
        libxml_use_internal_errors($internalErrors);

        $tables = $dom->getElementsByTagName('table');
        $items  = new Collection();

        if ( ! $tables instanceOf DOMNodeList) {
            return $items;
        }

        if ( ! isset($tables[5])) {
            throw new RuntimeException('Required "table" HTML tag does not found at index #2');
        }

        if ($tables[5]->getAttribute('class') != 't-data-grid') {
            return $items;
        }

        $rows = $tables[5]->childNodes->item(1)->childNodes;

        if ( ! $rows instanceOf DOMNodeList) {
            throw new RuntimeException('Required "tr" HTML tags does not found below "tbody"');
        }

        if ($rows->length == 0) {
            return $items;
        }

        for($i = 0; $i < $rows->length; ++$i) {
            $columns = $rows->item($i)->childNodes;
            
            if ($columns instanceOf DOMNodeList) {
                $date = $columns->item(0)->nodeValue;
                $date = Carbon::createFromFormat('d-M-Y', $date);
                $date = $date->format('Y-m-d');

                $description = $this->DOMinnerHTML($columns->item(1));
                $description = str_replace('<br>', '|', $description);
                $description = strip_tags($description);
                $description = preg_replace('/\s{3,}/', ' ', $description);
                $description = trim($description);
                $description = rtrim($description, '|');
                $description = preg_replace('/([ ]+)\|/', '|', $description);

                $firstAmount  = $columns->item(2)->nodeValue;
                $firstAmount = (float) str_replace(',', '', $firstAmount);

                $secondAmount = $columns->item(3)->nodeValue;
                $secondAmount = (float) str_replace(',', '', $secondAmount);

                $type   = ($firstAmount == 0) ? 'CR' : 'DB';
                $amount = ($firstAmount != 0) ? $firstAmount : $secondAmount;

                $uuidName = serialize($this->additionalEntityParams);
                $uuidName .= '.'.trim($date);
                $uuidName .= '.'.trim($description);
                $uuidName .= '.'.trim($type);
                $uuidName .= '.'.trim($amount);

                $data = array_merge($this->additionalEntityParams, [
                    'unique_id'   => $this->generateIdentifier($uuidName)->toString(), 
                    'date'        => trim($date), 
                    'description' => trim($description), 
                    'amount'      => trim($amount), 
                    'type'        => trim($type)
                ]);

                $items->push(new Entity($data));
            }
        }

        return $items;
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

        $options = $this->getRequestOptions();
        $options['headers'] = array_merge($options['headers'], [
            'Referer' => $this->effectiveUri
        ]);
        $options['on_stats'] = function (TransferStats $stats) {
            $this->effectiveUri = $stats->getEffectiveUri();
        };

        $url = $this->baseUri.'/ib-app/en/MI11300a';

        try {
            $response = $this->client()->request('POST', $url, $options);
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

            preg_match("/input\svalue=\"(.*?)\"\sname=\"t:formdata\"/", $body, $matches);
            if (isset($matches[1])) {
                $this->accountStatementFormdata = $matches[1];
            }

            preg_match("/id=\"accountSelect\"\sname=\"accountSelect\"><option\svalue=\"(.*?)\">/", $body, $matches);
            if (isset($matches[1])) {
                $this->accountId = $matches[1];
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

        $options = $this->getRequestOptions();
        $options['headers'] = array_merge($options['headers'], [
            'Referer' => $this->baseUri.'/ib-app/en/homewelcome'
        ]);

        $url = $this->baseUri.'/ib-app/en/homewelcome.layout.logout';

        try {
            $response = $this->client()->request('GET', $url, $options);
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
