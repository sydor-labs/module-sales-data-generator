# Perspective_SalesDataGenerator

[Українська](README.md) · **English**

A developer tool for Magento 2. It fills a store with test customers and orders spread over
a given period in the past. Useful for building and checking reports, dashboards and analytics
that need a believable sales history.

> The module is meant for development and test environments only. Generation is blocked in production mode.

## Features

- **Realistic timeline.** Orders follow weekdays (quieter weekends), seasons (Black Friday week,
  December, the January and summer dips), gradual store growth, daily noise and an hourly traffic
  curve in the store timezone.
- **Real checkout.** Orders are placed through a quote, so totals, taxes, shipping, payment
  and stock reservations are calculated exactly as for a regular order.
- **Order lifecycle.** Invoice, shipment, cancellation, hold and refund, with plausible delays
  between the steps. All dates are moved into the past together with documents and grids.
- **Customers and guests.** Customers register before their orders. A few loyal customers buy
  much more often than the rest. The share of guest orders is configurable.
- **Catalog.** If needed, the generator first creates a category tree and simple products with
  prices and stock, so it also works on an empty store.
- **Product popularity.** A long-tail distribution gives reports clear bestsellers.
  Simple and configurable products are supported.
- **Weighted settings.** Payment methods, shipping methods and order statuses are picked
  by weights set in the admin.
- **Millions of entities.** Only the number of customers and orders per day is stored; exact moments
  and buyers are rebuilt from the seed during processing, so the plan size does not depend on the
  number of orders.
- **Parallel processing through a queue.** Several RabbitMQ consumers process different days of one job.
  An interrupted job continues without duplicates.
- **Reproducibility.** With a seed, the same set of parameters gives the same data.

## Requirements

- Magento Open Source / Adobe Commerce 2.4.7 or later
- PHP 8.3+
- RabbitMQ configured as the `amqp` connection in `app/etc/env.php`
- Products that can be bought (e.g. Luma sample data), or catalog generation by the job itself
- At least one active offline payment method (e.g. Check / Money order)
  and one active carrier (e.g. Flat Rate)

## Installation

### With Composer

```bash
composer config repositories.sales-data-generator vcs https://github.com/sydor-labs/module-sales-data-generator
composer require --dev perspectiveteam/module-sales-data-generator:^1.0
bin/magento module:enable Perspective_SalesDataGenerator
bin/magento setup:upgrade
```

### Manually

Copy the repository contents to `app/code/Perspective/SalesDataGenerator`, then run:

```bash
bin/magento module:enable Perspective_SalesDataGenerator
bin/magento setup:upgrade
```

## Configuration

**Stores → Configuration → Perspective Studio → Sales Data Generator**

![Generator settings: payment and shipping methods, order statuses and processing options](images/system_config.png)

### Generated Orders

Three "option + weight" tables. Weights are relative: a row's share is its weight divided by the sum
of all weights. For example, weights 80 and 20 give the same 80% / 20% as 4 and 1. The **Share**
column shows the resulting share while editing.

| Field | Options | Default |
|---|---|---|
| Payment Methods | Active offline payment methods | `checkmo` 100 |
| Shipping Methods | Methods of active carriers | `flatrate_flatrate` 100 |
| Order Statuses | Statuses of the new, holded, processing, complete, closed and canceled states, custom ones included | complete 79, canceled 10, closed 8, processing 3 |

On save, the module checks that there are no duplicates, every option is available, weights are
not negative and at least one weight is above zero.

How the settings are used:
- If the chosen shipping method has no rate for the order address, the generator takes another
  configured one. The same goes for a payment method that is not available for the order
  (because of the country, currency or total).
- The state of a status decides what happens to the order:

  | State | Actions |
    |---|---|
  | new | placement only |
  | holded | placement and hold |
  | processing | invoice |
  | complete | invoice and shipment |
  | closed | invoice, shipment and full refund |
  | canceled | cancellation |

  A custom status is set once its state is reached. Recent orders whose next step would fall
  in the future stay in the earlier state.

### Generation

| Field | Description | Default |
|---|---|---|
| Batch Size | How many customers or orders one queue message processes | 100 |
| Parallel Workers | How many message chains are started per job; parallel processing needs as many consumers | 1 |
| Generate Sales Data... | Opens the page that starts a generation | — |

## Usage

### Admin

**Stores → Configuration → Perspective Studio → Sales Data Generator → Generate Sales Data...**

![Generation form](images/before_generate_config.png)

The form has the same parameters as the CLI. Below the form is a table of recent jobs: status,
progress, orders by status, errors and a **Resume** button. Clicking **Generate** creates a job
and queues it, so a running consumer is needed to process it:

```bash
bin/magento queue:consumers:start salesDataGenerator
```

For parallel processing, start as many consumers as **Parallel Workers** is set to, for example
in several terminals or with `--multi-process=1`, `--multi-process=2`, … Each consumer takes one
message at a time, so they do not take work from each other.

#### Job statuses

The **Recent Jobs** table does not update by itself: click **Refresh** to see the current state.

Right after creation a job is **queued** and waits for a consumer to take the first message:

![Job in the queued status](images/queued_job.png)

While it is processed the status is **running**. The **Catalog**, **Customers** and **Orders**
columns show how many entities have been created so far, and **Order Statuses** shows the breakdown
of the orders placed so far. The catalog is created first, so at this point it is usually complete:

![Job in the running status](images/running_job.png)

Once every segment is processed, the job briefly turns **finishing** (core report statistics are
refreshed) and then **done**:

![Job in the done status](images/done_job.png)

Orders that could not be placed are counted in **Orders** as processed and shown below it as
a separate "N failed" line. If a job is **stalled** or **failed**, a **Resume** button appears in
the **Action** column (see [Recovery after a failure](#recovery-after-a-failure)).

### CLI

```bash
bin/magento sales-data:generate [options]
```

| Option | Description | Default |
|---|---|---|
| `--customers` | How many customers to register | none |
| `--guest-ratio` | Share of guest orders, 0..1 | 0.25 |
| `--categories` | How many categories to create before customers and orders | none |
| `--products` | How many simple products to create before customers and orders | none |
| `--orders` | How many orders to place | none |
| `--from` | First day of the period, `YYYY-MM-DD` in the store timezone | one year ago |
| `--to` | Last day of the period, `YYYY-MM-DD` in the store timezone | today |
| `--store` | Store view code | default store view |
| `--seed` | Seed to reproduce a result | — |
| `--skip-statistics` | Do not refresh core report statistics at the end | — |
| `--queue` | Hand the job to a consumer instead of running it in the current process | — |

Examples:

```bash
# 500 customers and 5000 orders over 2025, run in the current process
bin/magento sales-data:generate --customers=500 --orders=5000 --from=2025-01-01 --to=2025-12-31

# The same through the queue
bin/magento sales-data:generate --customers=500 --orders=5000 --from=2025-01-01 --to=2025-12-31 --queue

# Reproducible run
bin/magento sales-data:generate --orders=1000 --from=2026-01-01 --to=2026-06-30 --seed=42
```

Entity counts are optional: a job creates only what is set. A job is validated before it is
created and is not started if it is bound to fail:

- no entity is set;
- the store view is disabled;
- there are orders, the guest share is below 1 and there are no customers;
- there are orders, no products are generated and the store has no products that can be bought;
- none of the configured payment or shipping methods is active;
- the configured statuses do not belong to the supported states.

Continue an interrupted or failed job:

```bash
bin/magento sales-data:resume <job_id>          # in the current process
bin/magento sales-data:resume <job_id> --queue  # through a consumer
```

## How it works

1. **Planning** (`Model/Job/Planner`). The job parameters are saved together with a seed (random
   if none is given), the planning time (cutoff, after which there are no events) and a unique token.
   Customers and orders are spread over days, and only the count per day is written to the
   `perspective_sales_data_generator_segment` table. For a year that is a few hundred rows,
   whether there are a thousand orders or ten million.
2. **Moments** (`Model/Timeline`). A day is split into hours, an hour into minutes, a minute into
   seconds, each level with an exact total (systematic sampling) and noise. Any slice of a day's
   sorted moments, e.g. positions 500 to 999 of 500 000, is rebuilt from the seed.
3. **Processing** (`Model/Job/Processor`). The catalog first (categories, then products), then segments
   by day, customers before orders. Products wait for categories, and a day's orders wait for the whole
   catalog and for all customers up to that day. Each worker locks its segment, so several processes
   work on one job without conflicts.
4. **Buyers** (`Model/BuyerSelector`, `Model/CustomerLocator`). An order's buyer is picked by position
   among the customers registered before the order time. Every tenth customer is loyal and buys more
   often. Customer IDs are not stored: the customer's email is rebuilt from the seed and looked up
   through the unique `(email, website_id)` index.
5. **Backdating.** Magento sets the current time on save, so `created_at` and `updated_at` are
   rewritten directly in the tables of customers, orders, items, quotes, status history, invoices,
   shipments, credit memos and their grids.
6. **Finishing.** The last worker that finds no work refreshes core report statistics (unless that
   is disabled) and saves the job summary.

Stock management is turned off during generation (only for the generator process), so large runs
do not stop when products sell out. Store settings are not changed.

Every 20 customers or orders and at the end of a batch, the in-memory caches of Magento services are
emptied (`Model/RuntimeCacheCleaner`, list in `di.xml`): quote, order, document and product repositories,
customer registries, entity snapshots and so on. Otherwise a consumer's memory would grow by ~0.6 MB
with every order. With the cleanup, a consumer peaks at about 130 MB of PHP memory regardless of job
and batch size. The interval is set by the `cleanCachesEvery` argument of `Model/Job/Processor` in `di.xml`.

### Recovery after a failure

Progress is saved in segments after each part of a batch. Generated entities are marked with the
job token, so an interrupted batch does not create duplicates:

| Entity | Mark |
|---|---|
| Customer | email `firstname.lastname.<token>.<position>@example.com` |
| Guest | email `firstname.lastname.<token>.<position>@example.org` |
| Order | `sales_order.ext_order_id` = `sdg-<token>-<position>` |
| Category | URL key `name-<token>-<position>` |
| Product | SKU `sdg-<token>-<position>`, URL key `name-<token>-<position>` |

When resuming, the generator checks what already exists in the database, not only the saved counter.

A message chain can break, for example when a consumer is killed mid-batch or a debugger session
is stopped while the consumer sat at a breakpoint. Magento treats a redelivered message as already
processed (`queue_lock`) and discards it. The job then becomes **stalled**. It can be resumed:

- with the **Resume** button in the admin or the `sales-data:resume` command;
- automatically: the `perspective_sales_data_generator_watchdog` cron job (every 5 minutes) resumes
  jobs without progress for more than 10 minutes, including those whose start messages were lost
  before the first batch. While the queue still has unread messages (consumers are busy or not
  running), the watchdog resumes nothing. A job whose batch keeps failing with an error is marked
  **failed** after 3 attempts without progress.

The watchdog works only when Magento cron is running.

## Reports

- **Core reports** (Reports → Sales, Reports → Products → Bestsellers) read aggregated tables.
  The generator refreshes them at the end of a job, like the *Refresh Lifetime Statistics* button.
  The `--skip-statistics` option turns this step off.
- **Custom reports and analytics modules** that aggregate orders incrementally by `updated_at`
  will not see orders dated in the past. They need a full rebuild after generation.

## Generated data

- **Customers:** countries UA (55%), PL (20%), DE (15%), US (10%), with a default address, gender
  and date of birth. The password of all customers is `Password123!`.
- **Orders:** 1 to 5 items, mostly 1 piece each. Products are taken from the whole store catalog:
  existing and generated.
- **Categories:** a tree under the store's root category. The first √n categories are departments with
  distinct names (in the menu), the rest are subcategories under them.
- **Products:** simple, enabled, visible in catalog and search, on the store's website, priced 5–500
  (more cheap ones), with weight, the default tax class, stock of 1000 and one or two of the job's categories.
- **Emails** about new orders are not sent.

Repeated runs add new data to the existing data. The module has no automatic cleanup of generated
data; it can be found by the `example.com` / `example.org` domains and the `sdg-` prefix in `ext_order_id`.
Quotes of generated orders remain in the database.

## Limitations

- Offline payment methods only: online gateways need real payment tokens.
- Products with required custom options are not added, nor are bundle, grouped and downloadable products.
- Only simple products are generated, without images; products and categories are created with the current date.
- The generated catalog stays in the store after the job finishes.
- Season factors are rough estimates, not measured values. Store growth is calculated within
  the job period (+50% from start to end).
- Current prices and settings are used, not those in effect on the order date.
- Speed is roughly 0.2 s per order per worker; millions of orders take days even with several workers.
- A customer's email is rebuilt from the seed, so the name lists in `PersonGenerator` must not change
  until the job is finished (otherwise the customer lookup falls back to a slow method).
- Orders found while resuming are not included in the job summary.

## License

MIT
