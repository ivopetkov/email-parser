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

        $bodyParts = $this->getBodyParts($email);
        foreach ($bodyParts as $bodyPart) {
            $contentDisposition = strtolower($this->getHeaderValueAndOptions($bodyPart[0], 'Content-Disposition')[0]);
            $contentTypeData = $this->getHeaderValueAndOptions($bodyPart[0], 'Content-Type');
            if ($bodyPart[2] === 1 && ($contentDisposition === 'inline' || $contentDisposition === 'attachment')) {
                $attachmentData = [];
                $attachmentData['mimeType'] = strlen($contentTypeData[0]) > 0 ? $contentTypeData[0] : null;
                $attachmentData['name'] = isset($contentTypeData[1]['name']) ? $this->decodeMIMEEncodedText($contentTypeData[1]['name']) : null;
                if ($contentDisposition === 'inline') {
                    $id = trim($this->getHeaderValue($bodyPart[0], 'Content-ID'), '<>');
                    $attachmentData['id'] = strlen($id) > 0 ? $id : null;
                }
                $attachmentData['content'] = $this->decodeBodyPart($bodyPart[0], $bodyPart[1]);
                $result[$contentDisposition === 'inline' ? 'embeds' : 'attachments'][] = $attachmentData;
            } else {
                $contentData = [];
                $contentData['mimeType'] = strlen($contentTypeData[0]) > 0 ? $contentTypeData[0] : null;
                $contentData['encoding'] = isset($contentTypeData[1]['charset']) ? $contentTypeData[1]['charset'] : null;
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
                        if (isset($part{0})) {
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
            return ['email' => trim($matches[2]), 'name' => trim($this->decodeMIMEEncodedText(trim(trim(trim($matches[1]), '"\''))))];
        }
        return ['email' => trim($address), 'name' => ''];
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
        if ($parentContentType === null || $parentContentType === 'multipart/alternative' || $parentContentType === 'multipart/related' || $parentContentType === 'multipart/mixed' || $parentContentType === 'multipart/signed') {
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
