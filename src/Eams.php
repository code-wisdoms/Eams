<?php
namespace CodeWisdoms;

use GuzzleHttp\Client;

class Eams
{
    private $client = null;
    private const BASE_URL = 'https://eams.dwc.ca.gov/WebEnhancement/';
    private const URL_INFORMATION_CAPTURE = 'InformationCapture';
    private const URL_INJURED_WORKER_FINDER = 'InjuredWorkerFinder';

    public function __construct()
    {
        $this->client = new Client(['base_uri' => self::BASE_URL, 'cookies' => true]);
        $this->initRequest();
        $this->captureInfo();
    }
    /**
     * Find EAMS record by ADJ number
     *
     * @param string|int $adj_number
     * @return array
     * @throws \Throwable
     */
    public function findByAdj($adj_number): array
    {
        $response = $this->client->post(self::URL_INJURED_WORKER_FINDER, [
            'form_params' => [
                'caseNumber' => $adj_number,
                'firstName' => '',
                'lastName' => '',
                'dateOfBirth' => '',
                'city' => '',
                'zipCode' => '',
                'action' => "Search",
            ],
        ]);

        $dom = new \DOMDocument();
        $dom->loadHTML($response->getBody()->getContents());
        $elems = $dom->getElementsByTagName('table');
        $data = [];
        foreach ($elems as $index => $table) {
            if ($index < 1) {
                continue;
            }
            $rows = $table->getElementsByTagName('tr');
            foreach ($rows as $rowIndex => $row) {
                if ($rowIndex < 1) {
                    continue;
                }
                foreach ($row->childNodes as $tdIndex => $col) {
                    $value = '';
                    if ($col->hasChildNodes() && $col->lastChild->nodeName == 'a') {
                        $col = $col->lastChild;
                        if ($col->attributes->getNamedItem('href')) {
                            $urls = $this->getUrlInfo($col->attributes->getNamedItem('href')->nodeValue, true);

                            $value = [];
                            foreach ($urls as $url) {
                                $value[] = $this->getCaseDetails($url);
                            }
                            $data[$rowIndex - 1]['details'] = $value;
                            continue;
                        }
                    } else {
                        $value = $col->nodeValue;
                    }
                    $key = strtolower($rows->item(0)->childNodes->item($tdIndex)->nodeValue);
                    $key = str_replace('injured worker', '', $key);
                    $key = str_replace(' ', '_', trim($key));
                    $data[$rowIndex - 1][$key] = $value;
                }
            }
        }
        return $data;
    }
    private function initRequest()
    {
        return $this->client->get('');
    }
    private function captureInfo()
    {
        return $this->client->post(self::URL_INFORMATION_CAPTURE, [
            'form_params' => [
                'requesterFirstName' => 'test',
                'requesterLastName' => 'tEST',
                'UAN' => '',
                'email' => 'admin@admin.com',
                'reason' => 'APPORTIONMENT',
                'action' => 'Next',
            ],
        ]);
    }
    private function getUrlInfo(string $url, bool $return_only_urls = false): array
    {
        $response = $this->client->get($url);

        $dom = new \DOMDocument();
        $dom->loadHTML($response->getBody()->getContents());
        $elems = $dom->getElementsByTagName('table');
        $data = [];
        foreach ($elems as $index => $table) {
            $rows = $table->getElementsByTagName('tr');
            foreach ($rows as $rowIndex => $row) {
                foreach ($row->childNodes as $tdIndex => $col) {
                    $value = '';
                    if ($col->hasChildNodes() && $col->lastChild->nodeName == 'a') {
                        $col = $col->lastChild;
                        if ($col->attributes->getNamedItem('href')) {
                            $value = $col->attributes->getNamedItem('href')->nodeValue;
                        }
                    } else {
                        if ($return_only_urls) {
                            continue;
                        }
                        $value = $col->nodeValue;
                    }
                    if ($return_only_urls) {
                        $data[] = $value;
                    } else {
                        $key = strtolower($rows->item(0)->childNodes->item($tdIndex)->nodeValue);
                        $key = str_replace('injured worker', '', $key);
                        $key = str_replace(' ', '_', trim($key));
                        $data[$index][$rowIndex][$key] = $value;
                    }
                }
            }
        }
        return $data;
    }
    private function getCaseDetails(string $url): array
    {
        $response = $this->client->get($url);

        $dom = new \DOMDocument();
        $dom->loadHTML($response->getBody()->getContents());
        $elems = $dom->getElementsByTagName('table');
        $data = [];
        foreach ($elems as $index => $table) {
            $rows = $table->getElementsByTagName('tr');
            switch ($index) {
                case 0:
                case 1:{
                        foreach ($rows as $rowIndex => $row) {
                            if ($rowIndex < 1) {
                                continue;
                            }
                            foreach ($row->childNodes as $tdIndex => $col) {
                                if (!trim($rows->item(0)->childNodes->item($tdIndex)->nodeValue)) {
                                    continue;
                                }
                                $value = self::_getValueFromDom($col);
                                $key = strtolower($rows->item(0)->childNodes->item($tdIndex)->nodeValue);
                                $key = str_replace('injured worker', '', $key);
                                $key = str_replace(' ', '_', trim($key));
                                $data[$key] = $value;
                            }
                        }
                        break;
                    }
                case 2:{
                        if (stripos($rows->item(0)->firstChild->nodeValue, 'body part') !== false) {
                            foreach ($rows as $rowIndex => $row) {
                                foreach ($row->childNodes as $tdIndex => $col) {
                                    if ($tdIndex % 2 === 0) {
                                        continue;
                                    }
                                    $data['body_parts'][] = self::_decodeText($col->nodeValue);
                                }
                            }
                            break;
                        }
                    }
                case 3:{
                        foreach ($rows as $rowIndex => $row) {
                            if ($rowIndex < 1) {
                                continue;
                            }
                            foreach ($row->childNodes as $tdIndex => $col) {
                                if (!trim($rows->item(0)->childNodes->item($tdIndex)->nodeValue)) {
                                    continue;
                                }
                                $value = self::_getValueFromDom($col);
                                $key = strtolower($rows->item(0)->childNodes->item($tdIndex)->nodeValue);
                                $key = str_replace('injured worker', '', $key);
                                $key = str_replace(' ', '_', trim($key));
                                $data['participants'][$rowIndex - 1][$key] = $value;
                            }
                        }
                        break;
                    }
            }
        }
        return $data;
    }
    private static function _getValueFromDom(\DOMNode $col)
    {
        if ($col->hasChildNodes() && $col->lastChild->nodeName != '#text') {
            $child = $col->lastChild;
            if ($child->nodeName == 'a' && $child->hasAttributes() && $child->attributes->getNamedItem('href')) {
                return $child->attributes->getNamedItem('href')->nodeValue;
            } elseif ($child->nodeName == 'span') {
                return self::_decodeText($child->nodeValue);
            } elseif ($child->nodeName == 'input' && $child->hasAttributes() && $child->attributes->getNamedItem('type')) {
                switch ($child->attributes->getNamedItem('type')->nodeValue) {
                    case 'checkbox':
                        return !$child->attributes->getNamedItem('checked') ? 0 : 1;
                }
            }
        } else {
            return self::_decodeText($col->nodeValue);
        }
    }
    private static function _decodeText(string $text)
    {
        return trim(str_replace('&nbsp;', ' ', htmlentities($text)));
    }
}
