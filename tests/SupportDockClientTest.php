<?php

declare(strict_types=1);

namespace SupportDock\Tests;

use PHPUnit\Framework\TestCase;
use SupportDock\SupportDockClient;
use SupportDock\Exception\ValidationException;

class SupportDockClientTest extends TestCase
{
    public function testConstructorRequiresApiKey(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('apiKey is required');

        new SupportDockClient(['apiKey' => '']);
    }

    public function testConstructorSetsDefaults(): void
    {
        $client = new SupportDockClient(['apiKey' => 'sdk_test123']);

        $this->assertInstanceOf(SupportDockClient::class, $client);
    }

    public function testSendFeedbackRequiresMessage(): void
    {
        $client = new SupportDockClient(['apiKey' => 'sdk_test123']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('message is required');

        $client->sendFeedback(['message' => '']);
    }

    public function testSendFeedbackRejectsTooManyAttachments(): void
    {
        $client = new SupportDockClient(['apiKey' => 'sdk_test123']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Maximum 3 PDF attachments allowed');

        $pdf = ['name' => 'a.pdf', 'data' => 'data:application/pdf;base64,JVBERi0='];
        $client->sendFeedback([
            'message' => 'hi',
            'attachments' => [$pdf, $pdf, $pdf, $pdf],
        ]);
    }

    public function testSendFeedbackRejectsAttachmentWithoutName(): void
    {
        $client = new SupportDockClient(['apiKey' => 'sdk_test123']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Each attachment requires a name');

        $client->sendFeedback([
            'message' => 'hi',
            'attachments' => [['name' => '', 'data' => 'data:application/pdf;base64,JVBERi0=']],
        ]);
    }

    public function testSendFeedbackRejectsNonPdfAttachment(): void
    {
        $client = new SupportDockClient(['apiKey' => 'sdk_test123']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Attachments must be base64-encoded PDF data URLs');

        $client->sendFeedback([
            'message' => 'hi',
            'attachments' => [['name' => 'a.pdf', 'data' => 'data:image/png;base64,iVBOR']],
        ]);
    }

    public function testCreateFAQRequiresQuestion(): void
    {
        $client = new SupportDockClient(['apiKey' => 'sdk_test123']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('question is required');

        $client->createFAQ(['question' => '', 'answer' => 'test']);
    }

    public function testCreateFAQRequiresAnswer(): void
    {
        $client = new SupportDockClient(['apiKey' => 'sdk_test123']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('answer is required');

        $client->createFAQ(['question' => 'test', 'answer' => '']);
    }

    public function testUpdateFAQRequiresFaqId(): void
    {
        $client = new SupportDockClient(['apiKey' => 'sdk_test123']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('faqId is required');

        $client->updateFAQ('', ['question' => 'updated']);
    }

    public function testDeleteFAQRequiresFaqId(): void
    {
        $client = new SupportDockClient(['apiKey' => 'sdk_test123']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('faqId is required');

        $client->deleteFAQ('');
    }
}
