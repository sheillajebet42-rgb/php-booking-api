<?php
declare(strict_types=1);

namespace Tests;

use App\BookingService;
use App\ConflictException;
use App\Database;
use App\NotFoundException;
use App\Router;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BookingTest extends TestCase
{
    private BookingService $svc;
    private Router $router;

    protected function setUp(): void
    {
        $pdo = Database::connect('sqlite::memory:');
        Database::migrate($pdo);
        $this->svc = new BookingService($pdo);
        $this->router = new Router($this->svc);
    }

    public function testBookingAndListing(): void
    {
        $room = $this->svc->createRoom('Orion', 8);
        $b = $this->svc->book($room['id'], 'Ana', '2025-06-01 10:00:00', '2025-06-01 11:00:00');
        $this->assertSame('Ana', $b['guest']);
        $this->assertCount(1, $this->svc->listBookings($room['id']));
    }

    public function testOverlapIsRejectedButAdjacentIsAllowed(): void
    {
        $room = $this->svc->createRoom('Orion', 8);
        $this->svc->book($room['id'], 'Ana', '2025-06-01 10:00:00', '2025-06-01 11:00:00');

        foreach ([['09:30:00', '10:30:00'], ['10:15:00', '10:45:00'], ['10:30:00', '12:00:00'], ['09:00:00', '12:00:00']] as [$s, $e]) {
            try {
                $this->svc->book($room['id'], 'Bob', "2025-06-01 $s", "2025-06-01 $e");
                $this->fail("overlap $s-$e should be rejected");
            } catch (ConflictException) {
                $this->addToAssertionCount(1);
            }
        }
        // Back-to-back bookings are fine.
        $this->svc->book($room['id'], 'Bob', '2025-06-01 11:00:00', '2025-06-01 12:00:00');
        $this->svc->book($room['id'], 'Cy', '2025-06-01 09:00:00', '2025-06-01 10:00:00');
        $this->assertCount(3, $this->svc->listBookings($room['id']));
    }

    public function testDifferentRoomsDoNotClash(): void
    {
        $a = $this->svc->createRoom('A', 2);
        $b = $this->svc->createRoom('B', 2);
        $this->svc->book($a['id'], 'x', '2025-06-01 10:00:00', '2025-06-01 11:00:00');
        $this->svc->book($b['id'], 'y', '2025-06-01 10:00:00', '2025-06-01 11:00:00');
        $this->assertCount(1, $this->svc->listBookings($b['id']));
    }

    public function testValidation(): void
    {
        $room = $this->svc->createRoom('Orion', 8);
        $this->expectException(InvalidArgumentException::class);
        $this->svc->book($room['id'], 'Ana', '2025-06-01 11:00:00', '2025-06-01 10:00:00');
    }

    public function testBadDateFormat(): void
    {
        $room = $this->svc->createRoom('Orion', 8);
        $this->expectException(InvalidArgumentException::class);
        $this->svc->book($room['id'], 'Ana', 'tomorrow', '2025-06-01 10:00:00');
    }

    public function testUnknownRoomAndDuplicateRoom(): void
    {
        try {
            $this->svc->book(42, 'Ana', '2025-06-01 10:00:00', '2025-06-01 11:00:00');
            $this->fail('expected NotFoundException');
        } catch (NotFoundException) {
            $this->addToAssertionCount(1);
        }
        $this->svc->createRoom('Orion', 8);
        $this->expectException(ConflictException::class);
        $this->svc->createRoom('Orion', 4);
    }

    public function testCancelFreesTheSlot(): void
    {
        $room = $this->svc->createRoom('Orion', 8);
        $b = $this->svc->book($room['id'], 'Ana', '2025-06-01 10:00:00', '2025-06-01 11:00:00');
        $this->svc->cancel($b['id']);
        $this->svc->book($room['id'], 'Bob', '2025-06-01 10:00:00', '2025-06-01 11:00:00');
        $this->assertCount(1, $this->svc->listBookings($room['id']));
    }

    public function testRouterStatusCodes(): void
    {
        [$s, $room] = $this->router->handle('POST', '/rooms', ['name' => 'Orion', 'capacity' => 4]);
        $this->assertSame(201, $s);
        $payload = ['guest' => 'Ana', 'starts_at' => '2025-06-01 10:00:00', 'ends_at' => '2025-06-01 11:00:00'];
        $this->assertSame(201, $this->router->handle('POST', "/rooms/{$room['id']}/bookings", $payload)[0]);
        $this->assertSame(409, $this->router->handle('POST', "/rooms/{$room['id']}/bookings", $payload)[0]);
        $this->assertSame(400, $this->router->handle('POST', "/rooms/{$room['id']}/bookings", ['guest' => ''] + $payload)[0]);
        $this->assertSame(404, $this->router->handle('POST', '/rooms/999/bookings', $payload)[0]);
        $this->assertSame(404, $this->router->handle('GET', '/nope')[0]);
        $this->assertSame(204, $this->router->handle('DELETE', '/bookings/1')[0]);
        $this->assertSame(404, $this->router->handle('DELETE', '/bookings/1')[0]);
    }
}
