<?php
/*
 * Email Parser
 * https://github.com/ivopetkov/email-parser
 * Copyright (c) Ivo Petkov
 * Free to use under the MIT license.
 */

use IvoPetkov\EmailParser;

/**
 * @runTestsInSeparateProcesses
 */
class Test extends PHPUnit\Framework\TestCase
{

    /**
     * 
     */
    public function testParser()
    {
        $parser = new EmailParser();
        $email = 'Delivered-To: recipient@example.com
Received: by 144.144.144.144 with SMTP id id123;
        Tue, 26 Dec 2017 07:01:49 -0800 (PST)
X-Received: by 144.144.144.145 with SMTP id id124;
        Tue, 26 Dec 2017 07:01:49 -0800 (PST)
Return-Path: <id131-sender-server@example.com>
Received: from sender-server.example.com (sender-server.example.com. [144.144.144.146])
        by recipient-server.example.com with ESMTPS id id125
        for <recipient@example.com>
        (version=TLS1 cipher=ECDHE-RSA-AES128-SHA bits=128/128);
        Tue, 26 Dec 2017 07:01:49 -0800 (PST)
Received-SPF: pass (example.com: domain of sender-server@example.com designates 144.144.144.146 as permitted sender) client-ip=144.144.144.146;
Authentication-Results: recipient-server.example.com;
       dkim=pass header.i=@example.com header.s=id126 header.b=id127;
       spf=pass (example.com: domain of sender-server@example.com designates 144.144.144.146 as permitted sender) smtp.mailfrom=sender-server@example.com
DKIM-Signature: v=1; a=rsa-sha256; q=dns/txt; c=relaxed/simple; s=id128; d=example.com; t=1514300508; h=MIME-Version:From:Date:Message-ID:Subject:To:Content-Type; bh=id129; b=id129
X-Received: by 10.202.45.205 with SMTP id id130; Tue, 26 Dec 2017 07:01:46 -0800 (PST)
MIME-Version: 1.0
From: "sender at example.com" <sender@example.com>
Date: Tue, 26 Dec 2017 15:01:48 +0000
Message-ID: <id131-sender-server@example.com>
Subject: Example email
To: recipient@example.com
Content-Type: multipart/alternative; boundary="001a1137b910cdc17f405613f8f2b"
X-SES-Outgoing: 2017.12.26-144.144.144.146
Feedback-ID: id132

--001a1137b910cdc17f405613f8f2b
Content-Type: text/plain; charset="UTF-8"

This is the *body *of the message.

--001a1137b910cdc17f405613f8f2b
Content-Type: text/html; charset="UTF-8"

<div dir="ltr">This is the <b>body </b>of the message.</div>

--001a1137b910cdc17f405613f8f2b--';
        $result = $parser->parse($email);
        $this->assertTrue($result['subject'] === 'Example email');
    }

}
