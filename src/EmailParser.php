<?php

/*
 * Email Parser
 * https://github.com/ivopetkov/email-parser
 * Copyright (c) Ivo Petkov
 * Free to use under the MIT license.
 */

namespace IvoPetkov;

/**
 * 
 */
class EmailParser
{

    /**
     * 
     * @param string $email
     * @return array
     */
    public function parse(string $email): array
    {

        $result = [];
        $parts = explode("\r\n\r\n", $email, 2);
        $headers = $this->parseHeaders($parts[0]);
        $result['deliveryDate'] = strtotime($this->getHeaderValue($headers, 'Delivery-date'));
        $result['returnPath'] = $this->parseEmailAddress($this->getHeaderValue($headers, 'Return-path'))['email'];
        $priority = substr($this->getHeaderValue($headers, 'X-Priority'), 0, 1);
        $result['priority'] = strlen($priority) > 0 ? (int) $priority : null;
        $result['date'] = strtotime($this->getHeaderValue($headers, 'Date'));
        $result['subject'] = $this->decodeMIMEEncodedText($this->getHeaderValue($headers, 'Subject'));
        $result['to'] = $this->parseEmailAddresses($this->getHeaderValue($headers, 'To'));
        $result['from'] = $this->parseEmailAddress($this->getHeaderValue($headers, 'From'));
        $result['replyTo'] = $this->parseEmailAddresses($this->getHeaderValue($headers, 'Reply-To'));
        $result['cc'] = $this->parseEmailAddresses($this->getHeaderValue($headers, 'Cc'));
        $result['bcc'] = $this->parseEmailAddresses($this->getHeaderValue($headers, 'Bcc'));
        $result['content'] = [];
        $result['attachments'] = [];
        $result['embeds'] = [];
        $result['headers'] = [];
        foreach ($headers as $header) {
            $result['headers'][] = ['name' => $header[0], 'value' => $header[1]];
        }
        $contentTypeData = $this->getHeaderValueAndOptions($headers, 'Content-Type');
        $defaultCharset = isset($contentTypeData[1]['charset']) && strlen($contentTypeData[1]['charset']) > 0 ? strtolower(trim($contentTypeData[1]['charset'])) : null;

        $bodyParts = $this->getBodyParts($email);
        foreach ($bodyParts as $bodyPart) {
            $contentDispositionData = $this->getHeaderValueAndOptions($bodyPart[0], 'Content-Disposition');
            $contentDisposition = strtolower($contentDispositionData[0]);
            $contentTypeData = $this->getHeaderValueAndOptions($bodyPart[0], 'Content-Type');
            $mimeType = strlen($contentTypeData[0]) > 0 ? strtolower($contentTypeData[0]) : null;
            if ($mimeType === null && $bodyPart[2] === 0) {
                $mimeType = 'text/plain';
            }
            if ($bodyPart[2] === 1 && ($contentDisposition === 'attachment' || (isset($contentDispositionData[1]['filename']) && strlen($contentDispositionData[1]['filename']) > 0))) {
                $attachmentData = [];
                $attachmentData['mimeType'] = $mimeType;
                $attachmentData['name'] = isset($contentTypeData[1]['name']) ? $this->decodeMIMEEncodedText($contentTypeData[1]['name']) : null;
                if ($contentDisposition === 'inline') {
                    $id = trim($this->getHeaderValue($bodyPart[0], 'Content-ID'), '<>');
                    $attachmentData['id'] = strlen($id) > 0 ? $id : null;
                }
                $attachmentData['content'] = $this->decodeBodyPart($bodyPart[0], $bodyPart[1]);
                $result[$contentDisposition === 'inline' ? 'embeds' : 'attachments'][] = $attachmentData;
            } else {
                $charset = isset($contentTypeData[1]['charset']) && strlen($contentTypeData[1]['charset']) > 0 ? strtolower(trim($contentTypeData[1]['charset'])) : null;
                if ($charset === null) {
                    $charset = $defaultCharset;
                }
                $contentData = [];
                $contentData['mimeType'] = $mimeType;
                $contentData['encoding'] = $charset;
                $contentData['content'] = $this->decodeBodyPart($bodyPart[0], $bodyPart[1]);
                $result['content'][] = $contentData;
            }
        }
//        if (!empty($inlineAttachments) && strlen($result['html']) > 0) {
//            foreach ($inlineAttachments as $inlineAttachment) {
//                if (strlen($inlineAttachment['id']) > 0 && strlen($inlineAttachment['contentType']) > 0) {
//                    if (strpos($result['html'], 'cid:' . $inlineAttachment['id'])) {
//                        $result['html'] = str_replace('cid:' . $inlineAttachment['id'], 'data:' . $inlineAttachment['contentType'] . ';base64,' . $inlineAttachment['base64Content'], $result['html']);
//                    }
//                }
//            }
//        }

        return $result;
    }

    /**
     * 
     * @param string $text
     * @return string
     */
    private function decodeMIMEEncodedText(string $text): string
    {
        $result = '';
        $elements = imap_mime_header_decode($text);
        for ($i = 0; $i < count($elements); $i++) {
            $charset = $elements[$i]->charset;
            $text = $elements[$i]->text;
            $result .= (strlen($charset) > 0 && $charset !== 'default') ? $this->convertEncoding($text, 'utf-8', $charset) : $text;
        }
        return $result;
    }

    /**
     * 
     * @param string $headers
     * @return array
     */
    private function parseHeaders(string $headers): array
    {
        $lines = explode("\r\n", trim($headers));
        $temp = [];
        foreach ($lines as $line) {
            if (preg_match('/^[a-zA-Z0-9]/', $line) === 1) {
                $temp[] = trim($line);
            } else {
                if (sizeof($temp) > 0) {
                    $temp[sizeof($temp) - 1] .= ' ' . trim($line);
                } else {
                    $temp[] = trim($line);
                }
            }
        }
        $result = [];
        foreach ($temp as $line) {
            $parts = explode(':', $line, 2);
            $result[] = [trim($parts[0]), isset($parts[1]) ? trim($parts[1]) : ''];
        }
        return $result;
    }

    /**
     * 
     * @param array $headers
     * @param string $name
     * @return string
     */
    private function getHeaderValue(array $headers, string $name): string
    {
        $name = strtolower($name);
        foreach ($headers as $header) {
            if (strtolower($header[0]) === $name) {
                return $header[1];
            }
        }
        return '';
    }

    /**
     * 
     * @param array $headers
     * @param string $name
     * @return array
     */
    private function getHeaderValueAndOptions(array $headers, string $name): array
    {
        $name = strtolower($name);
        foreach ($headers as $header) {
            if (strtolower($header[0]) === $name) {
                $parts = explode(';', trim($header[1]));
                $value = trim($parts[0]);
                $options = [];
                unset($parts[0]);
                if (!empty($parts)) {
                    foreach ($parts as $part) {
                        $part = trim($part);
                        if (isset($part[0])) {
                            $optionParts = explode('=', $part, 2);
                            if (sizeof($optionParts) === 2) {
                                $options[strtolower(trim($optionParts[0]))] = trim(trim(trim($optionParts[1]), '"\''));
                            }
                        }
                    }
                }
                return [$value, $options];
            }
        }
        return ['', []];
    }

    /**
     * 
     * @param string $address
     * @return array
     */
    private function parseEmailAddress(string $address): array
    {
        $matches = [];
        preg_match("/(.*)\<(.*)\>/", $address, $matches);
        if (sizeof($matches) === 3) {
            return ['email' => strtolower(trim($matches[2])), 'name' => trim($this->decodeMIMEEncodedText(trim(trim(trim($matches[1]), '"\''))))];
        }
        return ['email' => strtolower(trim($address)), 'name' => ''];
    }

    /**
     * 
     * @param string $addresses
     * @return array
     */
    private function parseEmailAddresses(string $addresses): array
    {
        $result = [];
        $addresses = explode(',', $addresses);
        foreach ($addresses as $address) {
            $address = trim($address);
            if (strlen($address) > 0) {
                $result[] = $this->parseEmailAddress($address);
            }
        }
        return $result;
    }

    /**
     * 
     * @param string $email
     * @param string $parentContentType
     * @return array
     */
    private function getBodyParts(string $email, string $parentContentType = null, $level = 0): array
    {
        if ($parentContentType === null || $parentContentType === 'multipart/alternative' || $parentContentType === 'multipart/related' || $parentContentType === 'multipart/mixed' || $parentContentType === 'multipart/signed' || $parentContentType === 'multipart/report') {
            // First 2 lines separate the headers from the body
            $parts = explode("\r\n\r\n", $email, 2);
            $headers = $this->parseHeaders(trim($parts[0]));
            // When there is boundary
            $contentTypeData = $this->getHeaderValueAndOptions($headers, 'Content-Type');
            $contentType = $contentTypeData[0];
            $boundary = isset($contentTypeData[1]['boundary']) ? $contentTypeData[1]['boundary'] : '';
            if (strlen($boundary) > 0) {
                $parts = explode('--' . $boundary, $email, 2);
                $headers = $this->parseHeaders(trim($parts[0]));
                $body = '--' . $boundary . (isset($parts[1]) ? trim($parts[1]) : '');
            } else {
                $body = isset($parts[1]) ? trim($parts[1]) : '';
            }
        } else {
            $headers = [];
            $body = trim($email);
        }

        if (strlen($body) === 0) {
            return [];
        } else {
            $contentTypeData = $this->getHeaderValueAndOptions($headers, 'Content-Type');
            $contentType = $contentTypeData[0];
            $boundary = isset($contentTypeData[1]['boundary']) ? $contentTypeData[1]['boundary'] : '';
            if (strlen($boundary) > 0) {
                $startIndex = strpos($body, '--' . $boundary) + strlen($boundary) + 2;
                $endIndex = strpos($body, '--' . $body . '--') - 2;
                $bodyParts = explode('--' . $boundary, substr($body, $startIndex, $endIndex - $startIndex));
                $bodyParts = array_map('trim', $bodyParts);
                $temp = [];
                foreach ($bodyParts as $bodyPart) {
                    $childBodyParts = $this->getBodyParts($bodyPart, $contentType, 1);
                    $temp = array_merge($temp, $childBodyParts);
                }
                $bodyParts = $temp;
            } else {
                $bodyParts = [[$headers, trim($body), $level]];
            }
            return $bodyParts;
        }
    }

    /**
     * 
     * @param array $headers
     * @param string $body
     * @return string
     */
    private function decodeBodyPart(array $headers, string $body): string
    {
        $contentTransferEncoding = $this->getHeaderValue($headers, 'Content-Transfer-Encoding');
        if ($contentTransferEncoding === 'base64') {
            $body = base64_decode(preg_replace('/((\r?\n)*)/', '', $body));
        } elseif ($contentTransferEncoding === 'quoted-printable') {
            $body = quoted_printable_decode($body);
        } elseif ($contentTransferEncoding === '7bit') {
            
        } elseif ($contentTransferEncoding === '8bit') {
            $body = quoted_printable_decode(imap_8bit($body));
        }
        return $body;
    }

    public function convertEncoding(string $text, string $toEncoding, string $fromEncoding)
    {
        $toEncoding = strtolower($toEncoding);
        $fromEncoding = strtolower($fromEncoding);
        if (strlen($fromEncoding) > 0) {
            $encodingAliases = [
                'ascii' => ['646', 'us-ascii'],
                'big5' => ['big5-tw', 'csbig5'],
                'big5hkscs' => ['big5-hkscs', 'hkscs'],
                'cp037' => ['ibm037', 'ibm039'],
                'cp424' => ['ebcdic-cp-he', 'ibm424'],
                'cp437' => ['437', 'ibm437'],
                'cp500' => ['ebcdic-cp-be', 'ebcdic-cp-ch', 'ibm500'],
                'cp775' => ['ibm775'],
                'cp850' => ['850', 'ibm850'],
                'cp852' => ['852', 'ibm852'],
                'cp855' => ['855', 'ibm855'],
                'cp857' => ['857', 'ibm857'],
                'cp860' => ['860', 'ibm860'],
                'cp861' => ['861', 'cp-is', 'ibm861'],
                'cp862' => ['862', 'ibm862'],
                'cp863' => ['863', 'ibm863'],
                'cp864' => ['ibm864'],
                'cp865' => ['865', 'ibm865'],
                'cp866' => ['866', 'ibm866'],
                'cp869' => ['869', 'cp-gr', 'ibm869'],
                'cp932' => ['932', 'ms932', 'mskanji', 'ms-kanji'],
                'cp949' => ['949', 'ms949', 'uhc'],
                'cp950' => ['950', 'ms950'],
                'cp1026' => ['ibm1026'],
                'cp1140' => ['ibm1140'],
                'cp1250' => ['windows-1250'],
                'cp1251' => ['windows-1251'],
                'cp1252' => ['windows-1252'],
                'cp1253' => ['windows-1253'],
                'cp1254' => ['windows-1254'],
                'cp1255' => ['windows-1255'],
                'cp1256' => ['windows1256'],
                'cp1257' => ['windows-1257'],
                'cp1258' => ['windows-1258'],
                'euc_jp' => ['eucjp', 'ujis', 'u-jis'],
                'euc_jis_2004' => ['jisx0213', 'eucjis2004'],
                'euc_jisx0213' => ['eucjisx0213'],
                'euc_kr' => ['euckr', 'korean', 'ksc5601', 'ks_c-5601', 'ks_c-5601-1987', 'ksx1001', 'ks_x-1001'],
                'gb2312' => ['chinese', 'csiso58gb231280', 'euc-cn', 'euccn', 'eucgb2312-cn', 'gb2312-1980', 'gb2312-80', 'iso-ir-58'],
                'gbk' => ['936', 'cp936', 'ms936'],
                'gb18030' => ['gb18030-2000'],
                'hz' => ['hzgb', 'hz-gb', 'hz-gb-2312'],
                'iso2022_jp' => ['csiso2022jp', 'iso2022jp', 'iso-2022-jp'],
                'iso2022_jp_1' => ['iso2022jp-1', 'iso-2022-jp-1'],
                'iso2022_jp_2' => ['iso2022jp-2', 'iso-2022-jp-2'],
                'iso2022_jp_2004' => ['iso2022jp-2004', 'iso-2022-jp-2004'],
                'iso2022_jp_3' => ['iso2022jp-3', 'iso-2022-jp-3'],
                'iso2022_jp_ext' => ['iso2022jp-ext', 'iso-2022-jp-ext'],
                'iso2022_kr' => ['csiso2022kr', 'iso2022kr', 'iso-2022-kr'],
                'latin_1' => ['iso-8859-1', 'iso8859-1', '8859', 'cp819', 'latin', 'latin1', 'l1'],
                'iso8859_2' => ['iso-8859-2', 'latin2', 'l2'],
                'iso8859_3' => ['iso-8859-3', 'latin3', 'l3'],
                'iso8859_4' => ['iso-8859-4', 'latin4', 'l4'],
                'iso8859_5' => ['iso-8859-5', 'cyrillic'],
                'iso8859_6' => ['iso-8859-6', 'arabic'],
                'iso8859_7' => ['iso-8859-7', 'greek', 'greek8'],
                'iso8859_8' => ['iso-8859-8', 'hebrew'],
                'iso8859_9' => ['iso-8859-9', 'latin5', 'l5'],
                'iso8859_10' => ['iso-8859-10', 'latin6', 'l6'],
                'iso8859_13' => ['iso-8859-13'],
                'iso8859_14' => ['iso-8859-14', 'latin8', 'l8'],
                'iso8859_15' => ['iso-8859-15'],
                'johab' => ['cp1361', 'ms1361'],
                'mac_cyrillic' => ['maccyrillic'],
                'mac_greek' => ['macgreek'],
                'mac_iceland' => ['maciceland'],
                'mac_latin2' => ['maclatin2', 'maccentraleurope'],
                'mac_roman' => ['macroman'],
                'mac_turkish' => ['macturkish'],
                'ptcp154' => ['csptcp154', 'pt154', 'cp154', 'cyrillic-asian'],
                'shift_jis' => ['csshiftjis', 'shiftjis', 'sjis', 's_jis'],
                'shift_jis_2004' => ['shiftjis2004', 'sjis_2004', 'sjis2004'],
                'shift_jisx0213' => ['shiftjisx0213', 'sjisx0213', 's_jisx0213'],
                'utf_16' => ['u16', 'utf16'],
                'utf_16_be' => ['utf-16be'],
                'utf_16_le' => ['utf-16le'],
                'utf_7' => ['u7'],
                'utf_8' => ['u8', 'utf', 'utf8']
            ];
            $supportedEncodings = mb_list_encodings();
            $getEncoding = function($encoding) use ($supportedEncodings, $encodingAliases) {
                $encodings = array_merge([$encoding], isset($encodingAliases[$encoding]) ? $encodingAliases[$encoding] : []);
                foreach ($supportedEncodings as $supportedEncoding) {
                    if (array_search(strtolower($supportedEncoding), $encodings) !== false) {
                        return $supportedEncoding;
                    }
                }

                return null;
            };
            $toEncoding = $getEncoding($toEncoding);
            $fromEncoding = $getEncoding($fromEncoding);
            if ($toEncoding !== null && $fromEncoding !== null && $toEncoding !== $fromEncoding) {
                return mb_convert_encoding($text, $toEncoding, $fromEncoding);
            }
        }
        return $text;
    }

}
