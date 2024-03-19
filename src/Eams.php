<?php
namespace CodeWisdoms\Eams;

use GuzzleHttp\Client;

class Eams
{
    private $client = null;
    private const BASE_URL = 'https://eams.dwc.ca.gov/WebEnhancement/';
    private const URL_INFORMATION_CAPTURE = 'InformationCapture';
    private const URL_INJURED_WORKER_FINDER = 'InjuredWorkerFinder';
    private $init_params = [];

    public function __construct(array $init_params = [])
    {
        $this->client = new Client(['base_uri' => self::BASE_URL, 'cookies' => true]);
        $this->init_params = $init_params;
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
    public function findByAdj($adj_number, array $expand = ['case']): array
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

        libxml_use_internal_errors(true);
        @$dom->loadHTML($response->getBody()->getContents());
        libxml_clear_errors();

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
                $cols = $row->getElementsByTagName('td');
                foreach ($cols as $tdIndex => $col) {
                    $value = '';
                    if ($col->hasChildNodes() && $col->lastChild->nodeName == 'a') {
                        $col = $col->lastChild;
                        if ($col->attributes->getNamedItem('href') && in_array('case', $expand)) {
                            $urls = $this->getUrlInfo($col->attributes->getNamedItem('href')->nodeValue, true);

                            $value = [];
                            foreach ($urls as $url) {
                                $value[] = $this->getCaseDetails($url, in_array('events', $expand));
                            }
                            $data[$rowIndex - 1]['details'] = $value;
                            continue;
                        }
                    } else {
                        $value = $col->nodeValue;
                    }
                    $key = self::_getKeyFromDom(self::_getHeaderFromRow($rows)->item($tdIndex));
                    $data[$rowIndex - 1][$key] = $value;
                }
            }
        }
        return $data;
    }
    private function initRequest()
    {
        return $this->client->head('');
    }
    private function captureInfo()
    {
        return $this->client->post(self::URL_INFORMATION_CAPTURE, [
            'form_params' => [
                'requesterFirstName' => $this->init_params['firstName'] ?? 'test',
                'requesterLastName' => $this->init_params['lastName'] ?? 'test',
                'UAN' => $this->init_params['uan'] ?? '',
                'email' => $this->init_params['email'] ?? 'admin@admin.com',
                'reason' => $this->init_params['reason'] ?? 'APPORTIONMENT',
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
                $cols = $row->getElementsByTagName('td');
                foreach ($cols as $tdIndex => $col) {
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
                        $key = self::_getKeyFromDom(self::_getHeaderFromRow($rows)->item($tdIndex));
                        $data[$index][$rowIndex][$key] = $value;
                    }
                }
            }
        }
        return $data;
    }
    private function getCaseDetails(string $url, bool $include_events = false): array
    {
        $response = $this->client->get($url);

        $dom = new \DOMDocument();
        $dom->loadHTML($response->getBody()->getContents());
        $elems = $dom->getElementsByTagName('table');
        $data = [];
        foreach ($elems as $index => $table) {
            $rows = $table->getElementsByTagName('tr');
            $matches = [];
            if (preg_match('#body\s+part#i', $rows->item(0)->firstChild->nodeValue) === 1) {
                foreach ($rows as $rowIndex => $row) {
                    $cols = $row->getElementsByTagName('td');
                    foreach ($cols as $tdIndex => $col) {
                        if ($tdIndex % 2 === 0) {
                            continue;
                        }
                        $val = self::_decodeText($col->nodeValue);
                        $vals = explode(' ', $val);
                        if (is_numeric($vals[0])) {
                            $val = intval(array_shift($vals));
                        } else {
                            $val = null;
                        }
                        $data['body_parts'][] = [
                            'code' => $val,
                            'detail' => implode(' ', $vals),
                        ];
                    }
                }
            } elseif (preg_match('#(.*hearing.*)|(.*participant.*)#is', $rows->item(0)->firstChild->nodeValue, $matches) === 1) {
                $key_main = '';
                $matches = implode(' ', $matches);
                if (stripos($matches, 'hearing') !== false) {
                    $key_main = 'hearing_detail';
                } elseif (stripos($matches, 'participant') !== false) {
                    $key_main = 'participants';
                } else {
                    $key_main = 'misc';
                }
                foreach ($rows as $rowIndex => $row) {
                    if ($rowIndex < 1) {
                        continue;
                    }
                    $cols = $row->getElementsByTagName('td');
                    foreach ($cols as $tdIndex => $col) {
                        if (!trim(self::_getHeaderFromRow($rows)->item($tdIndex)->nodeValue)) {
                            continue;
                        }
                        $value = self::_getValueFromDom($col);
                        $key = self::_getKeyFromDom(self::_getHeaderFromRow($rows)->item($tdIndex));
                        $data[$key_main][$rowIndex - 1][$key] = $value;
                    }
                }
            } else {
                foreach ($rows as $rowIndex => $row) {
                    if ($rowIndex < 1) {
                        continue;
                    }
                    $cols = $row->getElementsByTagName('td');
                    foreach ($cols as $tdIndex => $col) {
                        if (!self::_getHeaderFromRow($rows)->item($tdIndex) || !trim(self::_getHeaderFromRow($rows)->item($tdIndex)->nodeValue)) {
                            if ($include_events) {
                                $url = self::_getValueFromDom($col);
                                if (stripos($url, 'CaseEventFinder') !== false) {
                                    $data['events'] = $this->getEventDetails($url);
                                }
                            }
                            continue;
                        }
                        $value = self::_getValueFromDom($col);
                        $key = self::_getKeyFromDom(self::_getHeaderFromRow($rows)->item($tdIndex));
                        if (@$data[$key]) {
                            if ($value) {
                                $data[$key] .= ', ' . $value;
                            }
                        } else {
                            $data[$key] = $value;
                        }
                    }
                }
            }
        }
        return $data;
    }
    private function getEventDetails(string $url): array
    {
        $response = $this->client->get($url);

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
                $cols = $row->getElementsByTagName('td');
                foreach ($cols as $tdIndex => $col) {
                    $value = self::_getValueFromDom($col);
                    $key = self::_getKeyFromDom(self::_getHeaderFromRow($rows)->item($tdIndex));
                    $data[$rowIndex - 1][$key] = $value;
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
    private static function _getKeyFromDom(\DOMNode $col): string
    {
        $key = strtolower($col->nodeValue);
        $key = str_replace('injured worker', '', $key);
        $key = str_replace([' ', '/', '\\'], '_', trim($key));
        return $key;
    }
    private static function _decodeText(string $text)
    {
        return htmlspecialchars_decode(preg_replace('#\s{2,}#', ', ', trim(str_replace('&nbsp;', ' ', htmlentities($text)))));
    }
    private static function _getHeaderFromRow(\DOMNodeList $list)
    {
        return $list->item(0)->getElementsByTagName('th');
    }
}
