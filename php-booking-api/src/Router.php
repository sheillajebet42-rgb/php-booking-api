<?php
declare(strict_types=1);

namespace App;

use InvalidArgumentException;

/** Minimal HTTP layer: maps (method, path) to BookingService calls and domain errors to status codes. */
final class Router
{
    public function __construct(private BookingService $service) {}

    /** @return array{0:int,1:array|null} [status, body] */
    public function handle(string $method, string $path, array $body = []): array
    {
        try {
            $path = '/' . trim($path, '/');
            if ($method === 'GET' && $path === '/health') {
                return [200, ['status' => 'ok']];
            }
            if ($path === '/rooms') {
                if ($method === 'GET') {
                    return [200, $this->service->listRooms()];
                }
                if ($method === 'POST') {
                    return [201, $this->service->createRoom((string) ($body['name'] ?? ''), (int) ($body['capacity'] ?? 0))];
                }
            }
            if (preg_match('#^/rooms/(\d+)/bookings$#', $path, $m)) {
                $roomId = (int) $m[1];
                if ($method === 'GET') {
                    return [200, $this->service->listBookings($roomId)];
                }
                if ($method === 'POST') {
                    return [201, $this->service->book(
                        $roomId,
                        (string) ($body['guest'] ?? ''),
                        (string) ($body['starts_at'] ?? ''),
                        (string) ($body['ends_at'] ?? '')
                    )];
                }
            }
            if ($method === 'DELETE' && preg_match('#^/bookings/(\d+)$#', $path, $m)) {
                $this->service->cancel((int) $m[1]);
                return [204, null];
            }
            return [404, ['error' => 'route not found']];
        } catch (InvalidArgumentException $e) {
            return [400, ['error' => $e->getMessage()]];
        } catch (NotFoundException $e) {
            return [404, ['error' => $e->getMessage()]];
        } catch (ConflictException $e) {
            return [409, ['error' => $e->getMessage()]];
        }
    }
}
