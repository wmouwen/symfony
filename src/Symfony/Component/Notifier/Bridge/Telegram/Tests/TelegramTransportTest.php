<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Notifier\Bridge\Telegram\Tests;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Notifier\Bridge\Telegram\TelegramOptions;
use Symfony\Component\Notifier\Bridge\Telegram\TelegramTransport;
use Symfony\Component\Notifier\Exception\TransportException;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Message\MessageInterface;
use Symfony\Component\Notifier\Message\SmsMessage;
use Symfony\Component\Notifier\Test\TransportTestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class TelegramTransportTest extends TransportTestCase
{
    public function createTransport(HttpClientInterface $client = null, string $channel = null): TelegramTransport
    {
        return new TelegramTransport('token', $channel, $client ?? $this->createMock(HttpClientInterface::class));
    }

    public function toStringProvider(): iterable
    {
        yield ['telegram://api.telegram.org', $this->createTransport()];
        yield ['telegram://api.telegram.org?channel=testChannel', $this->createTransport(null, 'testChannel')];
    }

    public function supportedMessagesProvider(): iterable
    {
        yield [new ChatMessage('Hello!')];
    }

    public function unsupportedMessagesProvider(): iterable
    {
        yield [new SmsMessage('0611223344', 'Hello!')];
        yield [$this->createMock(MessageInterface::class)];
    }

    public function testSendWithErrorResponseThrowsTransportException()
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessageMatches('/post.+testDescription.+400/');

        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->exactly(2))
            ->method('getStatusCode')
            ->willReturn(400);
        $response->expects($this->once())
            ->method('getContent')
            ->willReturn(json_encode(['description' => 'testDescription', 'error_code' => 400]));

        $client = new MockHttpClient(static function () use ($response): ResponseInterface {
            return $response;
        });

        $transport = $this->createTransport($client, 'testChannel');

        $transport->send(new ChatMessage('testMessage'));
    }

    public function testSendWithErrorResponseThrowsTransportExceptionForEdit()
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessageMatches('/edit.+testDescription.+404/');

        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->exactly(2))
            ->method('getStatusCode')
            ->willReturn(400);
        $response->expects($this->once())
            ->method('getContent')
            ->willReturn(json_encode(['description' => 'testDescription', 'error_code' => 404]));

        $client = new MockHttpClient(static function () use ($response): ResponseInterface {
            return $response;
        });

        $transport = $this->createTransport($client, 'testChannel');

        $transport->send(new ChatMessage('testMessage', (new TelegramOptions())->edit(123)));
    }

    public function testSendWithOptions()
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->exactly(2))
            ->method('getStatusCode')
            ->willReturn(200);

        $content = <<<JSON
            {
                "ok": true,
                "result": {
                    "message_id": 1,
                    "from": {
                        "id": 12345678,
                        "first_name": "YourBot",
                        "username": "YourBot"
                    },
                    "chat": {
                        "id": 1234567890,
                        "first_name": "John",
                        "last_name": "Doe",
                        "username": "JohnDoe",
                        "type": "private"
                    },
                    "date": 1459958199,
                    "text": "Hello from Bot!"
                }
            }
JSON;

        $response->expects($this->once())
            ->method('getContent')
            ->willReturn($content)
        ;

        $expectedBody = [
            'chat_id' => 'testChannel',
            'text' => 'testMessage',
            'parse_mode' => 'MarkdownV2',
        ];

        $client = new MockHttpClient(function (string $method, string $url, array $options = []) use ($response, $expectedBody): ResponseInterface {
            $this->assertStringEndsWith('/sendMessage', $url);
            $this->assertSame($expectedBody, json_decode($options['body'], true));

            return $response;
        });

        $transport = $this->createTransport($client, 'testChannel');

        $sentMessage = $transport->send(new ChatMessage('testMessage'));

        $this->assertEquals(1, $sentMessage->getMessageId());
        $this->assertEquals('telegram://api.telegram.org?channel=testChannel', $sentMessage->getTransport());
    }

    public function testSendWithOptionForEditMessage()
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->exactly(2))
            ->method('getStatusCode')
            ->willReturn(200);

        $content = <<<JSON
            {
                "ok": true,
                "result": {
                    "message_id": 1,
                    "from": {
                        "id": 12345678,
                        "first_name": "YourBot",
                        "username": "YourBot"
                    },
                    "chat": {
                        "id": 1234567890,
                        "first_name": "John",
                        "last_name": "Doe",
                        "username": "JohnDoe",
                        "type": "private"
                    },
                    "date": 1459958199,
                    "text": "Hello from Bot!"
                }
            }
JSON;

        $response->expects($this->once())
            ->method('getContent')
            ->willReturn($content)
        ;

        $client = new MockHttpClient(function (string $method, string $url) use ($response): ResponseInterface {
            $this->assertStringEndsWith('/editMessageText', $url);

            return $response;
        });

        $transport = $this->createTransport($client, 'testChannel');
        $options = (new TelegramOptions())->edit(123);

        $transport->send(new ChatMessage('testMessage', $options));
    }

    public function testSendWithOptionToAnswerCallbackQuery()
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->exactly(2))
            ->method('getStatusCode')
            ->willReturn(200);

        $content = <<<JSON
            {
                "ok": true,
                "result": true
            }
JSON;

        $response->expects($this->once())
            ->method('getContent')
            ->willReturn($content)
        ;

        $client = new MockHttpClient(function (string $method, string $url) use ($response): ResponseInterface {
            $this->assertStringEndsWith('/answerCallbackQuery', $url);

            return $response;
        });

        $transport = $this->createTransport($client, 'testChannel');
        $options = (new TelegramOptions())->answerCallbackQuery('123', true, 1);

        $transport->send(new ChatMessage('testMessage', $options));
    }

    public function testSendWithChannelOverride()
    {
        $channelOverride = 'channelOverride';

        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->exactly(2))
            ->method('getStatusCode')
            ->willReturn(200);
        $content = <<<JSON
            {
                "ok": true,
                "result": {
                    "message_id": 1,
                    "from": {
                        "id": 12345678,
                        "first_name": "YourBot",
                        "username": "YourBot"
                    },
                    "chat": {
                        "id": 1234567890,
                        "first_name": "John",
                        "last_name": "Doe",
                        "username": "JohnDoe",
                        "type": "private"
                    },
                    "date": 1459958199,
                    "text": "Hello from Bot!"
                }
            }
JSON;

        $response->expects($this->once())
            ->method('getContent')
            ->willReturn($content)
        ;

        $expectedBody = [
            'chat_id' => $channelOverride,
            'text' => 'testMessage',
            'parse_mode' => 'MarkdownV2',
        ];

        $client = new MockHttpClient(function (string $method, string $url, array $options = []) use ($response, $expectedBody): ResponseInterface {
            $this->assertSame($expectedBody, json_decode($options['body'], true));

            return $response;
        });

        $transport = $this->createTransport($client, 'defaultChannel');

        $messageOptions = new TelegramOptions();
        $messageOptions->chatId($channelOverride);

        $sentMessage = $transport->send(new ChatMessage('testMessage', $messageOptions));

        $this->assertEquals(1, $sentMessage->getMessageId());
        $this->assertEquals('telegram://api.telegram.org?channel=defaultChannel', $sentMessage->getTransport());
    }

    public function testSendWithMarkdownShouldEscapeSpecialCharacters()
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->exactly(2))
            ->method('getStatusCode')
            ->willReturn(200);

        $content = <<<JSON
            {
                "ok": true,
                "result": {
                    "message_id": 1,
                    "from": {
                        "id": 12345678,
                        "first_name": "YourBot",
                        "username": "YourBot"
                    },
                    "chat": {
                        "id": 1234567890,
                        "first_name": "John",
                        "last_name": "Doe",
                        "username": "JohnDoe",
                        "type": "private"
                    },
                    "date": 1459958199,
                    "text": "Hello from Bot!"
                }
            }
JSON;

        $response->expects($this->once())
            ->method('getContent')
            ->willReturn($content)
        ;

        $expectedBody = [
            'chat_id' => 'testChannel',
            'text' => 'I contain special characters \_ \* \[ \] \( \) \~ \` \> \# \+ \- \= \| \{ \} \. \! to send\.',
            'parse_mode' => 'MarkdownV2',
        ];

        $client = new MockHttpClient(function (string $method, string $url, array $options = []) use ($response, $expectedBody): ResponseInterface {
            $this->assertSame($expectedBody, json_decode($options['body'], true));

            return $response;
        });

        $transport = $this->createTransport($client, 'testChannel');

        $transport->send(new ChatMessage('I contain special characters _ * [ ] ( ) ~ ` > # + - = | { } . ! to send.'));
    }
}
