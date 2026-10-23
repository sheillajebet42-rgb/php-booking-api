# PHP Room Booking API
![CI](https://github.com/YOUR_USER/php-booking-api/actions/workflows/ci.yml/badge.svg)

Dependency-light REST API in PHP 8.2+ (strict types, PSR-4, PDO prepared statements). Runs on SQLite for zero setup and MySQL in Docker. Core feature: **conflict-free room booking**.

## Run
```bash
composer install && composer start           # SQLite, http://localhost:8000
docker compose up --build                    # MySQL
curl -X POST localhost:8000/rooms -d '{"name":"Orion","capacity":8}'
curl -X POST localhost:8000/rooms/1/bookings -d '{"guest":"Ana","starts_at":"2025-06-01 10:00:00","ends_at":"2025-06-01 11:00:00"}'
```
Routes: `GET/POST /rooms`, `GET/POST /rooms/{id}/bookings`, `DELETE /bookings/{id}`, `GET /health`.

## Test
`composer test` (PHPUnit, in-memory SQLite).

## Design decisions
- **Overlap rule:** two intervals clash iff `existing.start < new.end AND existing.end > new.start`, so back-to-back bookings are allowed. Tests cover partial, contained and adjacent cases.
- **Transaction + `SELECT ... FOR UPDATE` (MySQL)** on the room row serializes concurrent bookings of the same room and prevents double-booking races.
- **Prepared statements everywhere** (SQL injection), emulation off.
- **Router is a pure function** `(method, path, body) -> (status, body)`, so HTTP behaviour is unit-tested without a web server.
- **Domain exceptions** (`ConflictException`, `NotFoundException`) map to 409/404 in one place.
