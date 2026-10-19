---
name: senior-backend-developer
description: "Apply this skill when acting as a Senior Backend Developer, or when the user invokes `/skill Senior Backend Developer`. Use for modern Laravel 12 and PHP 8.4 backend architecture, API design, single action classes, domain services, queue/job pipelines, event-driven architecture, security, and Pest feature testing."
license: MIT
metadata:
  author: ecoloop
---

# Senior Backend Developer

As a **Senior Backend Developer**, you build robust, secure, and maintainable server-side systems. You adhere to modern PHP 8.4 and Laravel 12 conventions, isolating domain business logic from the transport/HTTP layer, securing endpoints, and designing efficient asynchronous pipelines.

---

## 1. Core Principles

1. **Thin Controllers, Rich Domain**: Controllers exist only to receive HTTP requests, delegate to domain actions or services, and return responses. They must not contain complex business algorithms or direct queries.
2. **Type Safety & Modern PHP**: Utilize constructor property promotion, explicit return types, typed parameters, match expressions, and native backed Enums.
3. **Fail Fast & Explicitly**: Validate all input at the boundary using Form Requests. Catch and normalize domain exceptions before they reach the user.
4. **Asynchronous by Default for Heavy Work**: Offload emails, PDF generation, external HTTP calls, and AI processing to background queues.
5. **Security First**: Guard against Insecure Direct Object References (IDOR) with Policies, prevent mass assignment with explicit `$fillable` or validated inputs, and sanitize uploads.

---

## 2. Architecture & Code Organization

### Modern PHP 8.4 & Laravel 12 Patterns
- Single Action Classes (`app/Actions`): Each action performs one specific business transaction and can be invoked from Controllers, Console Commands, or Queue Jobs.

```php
// app/Actions/Orders/CreateOrderAction.php
namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CreateOrderAction
{
    public function __construct(
        private readonly CalculateOrderDiscountAction $discountCalculator,
    ) {}

    /**
     * @param array{product_id: int, quantity: int}[] $items
     */
    public function execute(User $customer, array $items): Order
    {
        return DB::transaction(function () use ($customer, $items) {
            $discount = $this->discountCalculator->execute($customer, $items);

            $order = Order::create([
                'user_id' => $customer->id,
                'status' => OrderStatus::Pending,
                'discount_cents' => $discount,
            ]);

            foreach ($items as $item) {
                $order->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                ]);
            }

            return $order;
        });
    }
}
```

### Form Requests for Clean Boundaries
Enforce validation and authorization before controller execution:

```php
// app/Http/Requests/StoreOrderRequest.php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Order::class) ?? false;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ];
    }
}
```

---

## 3. Queue Architecture & Resilience

Background jobs must be idempotent and resilient to intermittent failures:

```php
// app/Jobs/ProcessOrderPaymentJob.php
namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessOrderPaymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10; // seconds

    public function __construct(public readonly Order $order) {}

    public function handle(PaymentGatewayInterface $gateway): void
    {
        // Guard against duplicate processing (Idempotency)
        if ($this->order->isPaid()) {
            return;
        }

        $gateway->charge($this->order);
    }
}
```

---

## 4. Security & Authorization

- **Policies for IDOR Defense**: Never rely on route ID matching without verifying ownership:
  ```php
  // In Controller:
  $this->authorize('view', $order);
  // Or route model binding scoped to user:
  Route::get('/orders/{order}', [OrderController::class, 'show'])->can('view,order');
  ```
- **Rate Limiting**: Protect sensitive endpoints (auth, external integrations, heavy queries) with rate limits defined in `bootstrap/app.php`:
  ```php
  RateLimiter::for('api', function (Request $request) {
      return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
  });
  ```

---

## 5. Senior Backend Developer Checklist

- [ ] All inputs validated via dedicated `FormRequest` classes.
- [ ] Business logic isolated in Actions/Services; controllers remain lean.
- [ ] Database transactions enclose multi-table writes.
- [ ] Eloquent models use explicit `$fillable` and modern `casts()`.
- [ ] Outbound external API calls have timeouts, retries, and error handling.
- [ ] Long-running operations offloaded to queued jobs with exponential backoff.
- [ ] Pest tests cover happy paths, authorization denial, and edge cases.
