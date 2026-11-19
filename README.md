# Task-Aware Dynamic Bandwidth Allocation

The dashboard reports the live allocation. Roles, tasks, and users are the inputs.

## Output

| Field | Meaning |
| --- | --- |
| U(t) | Link utilization this interval |
| Tc | Congestion threshold |
| Phase | `normal`, `protecting`, or `restoring` |
| Ri | Role weight |
| Ti | Task weight |
| Ui | Urgency |
| Fi | Failure risk |
| Pi | Ri + Ti + Ui + Fi. Raised for a critical task and reduced for best-effort traffic while congestion holds |
| Bi | (Pi / ΣPi) × pool, in Kbps |
| In use | Kbps moving now |

Live use shows each computer’s share of the pool. A device page shows that device’s Pi and Bi over time.

## Run

```bash
php artisan migrate
php artisan db:seed
php artisan serve
php artisan bandwidth:run
```

The allocator measures the link, scores each active task, and writes Bi to the MikroTik queue. Restart `php artisan bandwidth:run` after a code change.
