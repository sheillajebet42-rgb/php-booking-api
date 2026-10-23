<?php
declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class BookingService
{
    public const FORMAT = 'Y-m-d H:i:s';

    public function __construct(private PDO $pdo) {}

    public function createRoom(string $name, int $capacity): array
    {
        $name = trim($name);
        if ($name === '' || $capacity < 1) {
            throw new InvalidArgumentException('name and positive capacity are required');
        }
        $stmt = $this->pdo->prepare('INSERT INTO rooms (name, capacity) VALUES (?, ?)');
        try {
            $stmt->execute([$name, $capacity]);
        } catch (\PDOException $e) {
            if ($this->isUniqueViolation($e)) {
                throw new ConflictException("Room '$name' already exists");
            }
            throw $e;
        }
        return ['id' => (int) $this->pdo->lastInsertId(), 'name' => $name, 'capacity' => $capacity];
    }

    public function listRooms(): array
    {
        return $this->pdo->query('SELECT id, name, capacity FROM rooms ORDER BY name')->fetchAll();
    }

    public function book(int $roomId, string $guest, string $start, string $end): array
    {
        $guest = trim($guest);
        $s = self::parse($start);
        $e = self::parse($end);
        if ($guest === '') {
            throw new InvalidArgumentException('guest is required');
        }
        if ($e <= $s) {
            throw new InvalidArgumentException('end must be after start');
        }

        // Transaction + overlap check; on MySQL add "FOR UPDATE" on the room row to serialize concurrent bookings.
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $room = $this->pdo->prepare('SELECT id FROM rooms WHERE id = ?' . $lock);
            $room->execute([$roomId]);
            if (!$room->fetch()) {
                throw new NotFoundException("Room $roomId not found");
            }

            // Two intervals overlap iff each starts before the other ends.
            $clash = $this->pdo->prepare(
                'SELECT COUNT(*) FROM bookings WHERE room_id = ? AND starts_at < ? AND ends_at > ?'
            );
            $clash->execute([$roomId, $e->format(self::FORMAT), $s->format(self::FORMAT)]);
            if ((int) $clash->fetchColumn() > 0) {
                throw new ConflictException('Room is already booked for that time');
            }

            $ins = $this->pdo->prepare('INSERT INTO bookings (room_id, guest, starts_at, ends_at) VALUES (?, ?, ?, ?)');
            $ins->execute([$roomId, $guest, $s->format(self::FORMAT), $e->format(self::FORMAT)]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (\Throwable $t) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $t;
        }

        return ['id' => $id, 'room_id' => $roomId, 'guest' => $guest,
                'starts_at' => $s->format(self::FORMAT), 'ends_at' => $e->format(self::FORMAT)];
    }

    public function listBookings(int $roomId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, room_id, guest, starts_at, ends_at FROM bookings WHERE room_id = ? ORDER BY starts_at');
        $stmt->execute([$roomId]);
        return $stmt->fetchAll();
    }

    public function cancel(int $bookingId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM bookings WHERE id = ?');
        $stmt->execute([$bookingId]);
        if ($stmt->rowCount() === 0) {
            throw new NotFoundException("Booking $bookingId not found");
        }
    }

    private static function parse(string $value): DateTimeImmutable
    {
        $d = DateTimeImmutable::createFromFormat(self::FORMAT, $value);
        if ($d === false || $d->format(self::FORMAT) !== $value) {
            throw new InvalidArgumentException("Invalid datetime '$value', expected " . self::FORMAT);
        }
        return $d;
    }

    private function isUniqueViolation(\PDOException $e): bool
    {
        // SQLSTATE 23000 = integrity constraint violation (MySQL and SQLite).
        return $e->getCode() === '23000' || str_contains($e->getMessage(), 'UNIQUE');
    }
}
