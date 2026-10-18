<?php

namespace App\Tests\Entity;

use App\Entity\Task;
use PHPUnit\Framework\TestCase;

class TaskTest extends TestCase
{
    public function testPayloadDefaultsToNull(): void
    {
        $task = new Task();

        $this->assertNull($task->getPayload());
    }

    public function testSetPayload(): void
    {
        $task = new Task();
        $payload = json_encode(['scraper' => 'Fake', 'confirm_identifier' => 'fake:spawn']);
        $task->setPayload($payload);

        $this->assertSame($payload, $task->getPayload());
    }
}
