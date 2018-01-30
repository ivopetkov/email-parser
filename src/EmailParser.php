<?php

/*
 * Email Parser
 * https://github.com/ivopetkov/email-parser
 * Copyright 2017, Ivo Petkov
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
        $parts = explode(PHP_EOL.PHP_EOL, $email, 2);
        $headers = $this->parseHeaders($parts[0]);
        file_put_contents('dump_header.txt', serialize($headers));
        $result['returnPath'] = $this->getHeaderValue($headers, 'Return-Path');
        $priority = substr($this->getHeaderValue($headers, 'X-Priority'), 0, 1);
        $result['priority'] = strlen($priority) > 0 ? (int) $priority : null;
        $result['deliveryDate'] = strtotime($this->getHeaderValue($headers, 'Delivery-Date'));
        $result['date'] = strtotime($this->getHeaderValue($headers, 'Date'));
        $result['subject'] = $this->getHeaderValue($headers, 'Subject');
        $result['to'] = $this->parseEmailAddress($this->getHeaderValue($headers, 'toaddress'));
        $result['from'] = $this->parseEmailAddresses($this->getHeaderValues($headers, 'from'));
        $result['replyTo'] = $this->parseEmailAddresses($this->getHeaderValues($headers, 'Reply-To'));
        $result['sender'] = $this->parseEmailAddresses($this->getHeaderValues($headers, 'sender'));
        $result['cc'] = $this->parseEmailAddress($this->getHeaderValue($headers, 'Cc'));
        $result['bcc'] = $this->parseEmailAddress($this->getHeaderValue($headers, 'Bcc'));
        $result['content'] = [];
        $result['attachments'] = [];
        $result['embeds'] = [];
        $result['headers'] = [];
        foreach ($headers as $header => $value) {
            $result['headers'][] = ['name' => $header, 'value' => $value];
        }

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
            $result .= (strlen($charset) > 0 && $charset !== 'default') ? mb_convert_encoding($text, 'UTF-8', $charset) : $text;
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
        $result = \imap_rfc822_parse_headers($headers);
        return (array)$result;
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
        $name = str_replace('-', '_', $name);
        return isset($headers[$name]) ? $headers[$name] : '';
    }

    /**
     * 
     * @param array $headers
     * @param string $name
     * @return array
     */
    private function getHeaderValues(array $headers, string $name): array
    {
        $name = strtolower($name);
        $name = str_replace('-', '_', $name);
        return isset($headers[$name]) ? $headers[$name] : '';
    }

    /**
     * 
     * @param string $address
     * @return array
     */
    private function parseEmailAddress(string $address): array
    {
        $result = ['email' => '', 'name' => ''];
        $addressArray = explode('@', $address);
        $addressArrayLen = count($addressArray);
        if ($addressArrayLen !== 2) {
            return $result;
        }
        $result['name'] = $addressArray[0];
        $result['email'] = $address;
        return $result;
    }

    /**
     * 
     * @param string $address
     * @return array
     */
    private function parseEmailAddresses(array $address): array
    {
        $addressArray = (array)$address[0];
        $result['name'] = $addressArray['mailbox'];
        $result['email'] = $addressArray['mailbox'].'@'.$addressArray['host'];
        return $result;
    }

    /**
     * 
     * @param string $email
     * @return array
     */
    private function getBodyParts(string $email): array
    {
        $result = [];
        $emailSegment = explode('--', $email);
        if(count($emailSegment) === 0) {
            return $result;
        }
        $emailSegment = array_shift($emailSegment);
        foreach ($emailSegment as $segments) {
            $segment = explode(PHP_EOL, $segments);
            if(count($segment) === 0) {
                break;
            }
            foreach($segment as $value) {
                $contentType = $this->getContentType($value);
                $result['contentType'] = $contentType['contentType'];
                $result['contentType'] = $contentType['charset'];
            }
        }
        return $result;
    }

    /**
     * 
     * @param string $header
     * @return array
     */
    private function getContentType(string $header): array
    {
        $result = [];
        $headerArray = explode(';', $header);
        if(count($headerArray) !== 2) {
            return $result;
        }
        $charSet = $headerArray[1];
        $charSet = str_replace('charset="', '', $charSet);
        $charSet = str_replace('"', '', $charSet);
        $result['charset'] = strtolower($charSet);
        $contentType = explode(':', $headerArray[0]);
        if(count($contentType) !== 2) {
            return $result;
        }
        $result['contentType'] = $contentType[1];
        return $result;
    }

    /**
     * 
     * @param array $headers
     * @param string $body
     * @return string
     */
    private function decodeBodyPart(array $headers, string $body): string
    {
        $contentTypeData = $this->getHeaderValueAndOptions($headers, 'Content-Type');

        $contentTransferEncoding = $this->getHeaderValue($headers, 'Content-Transfer-Encoding');
        if ($contentTransferEncoding === 'base64') {
            $body = base64_decode(preg_replace('/((\r?\n)*)/', '', $body));
        } elseif ($contentTransferEncoding === 'quoted-printable') {
            $body = quoted_printable_decode($body);
        } elseif ($contentTransferEncoding === '7bit') {
            // gurmi
            //$body = mb_convert_encoding(imap_utf7_decode($body), 'UTF-8', 'ISO-8859-1');
        }

        if (isset($contentTypeData[1]['charset']) && strtolower($contentTypeData[1]['charset']) !== 'utf-8') {
            $charset = strtolower($contentTypeData[1]['charset']);
            $encodings = mb_list_encodings();
            $found = false;
            foreach ($encodings as $encoding) {
                if (strtolower($encoding) === $charset) {
                    $found = true;
                    break;
                }
            }
            if ($found) {
                $body = mb_convert_encoding($body, 'UTF-8', $charset);
            }
        }

        return $body;
    }

}
