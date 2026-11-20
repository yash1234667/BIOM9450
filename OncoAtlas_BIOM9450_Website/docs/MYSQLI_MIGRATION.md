# OncoAtlas: MySQLi version (PetStore-style)

This branch starts from **feature/petstore-style-simplified-website**.
It changes how the **regular website** connects to MySQL, while keeping the TCGA database and prediction/model files unchanged.

## Why is there one small PDO file remaining?

Your teammate's existing function is defined as:

```php
function prediction_save(PDO $pdo, ...) { ... }
```

Changing that function to MySQLi would involve editing the model/integration code, which we agreed not to touch. Instead, **all normal website SQL now uses MySQLi** in `app/bootstrap.php`, and the existing model's PDO connection is isolated in `app/model_db.php`. The model only opens this connection when a prediction is saved.

This keeps your login, patient records, mutation explorer, AJAX APIs, feedback and TSV importer on MySQLi while preserving the model. Both connections use the same `config/config.php` database settings.

## 1. Shared connection: app/bootstrap.php

Equivalent PetStore file: `apps/db.php`.

```php
$connection = new mysqli($host, $username, $password, $database, $port);
$connection->set_charset('utf8mb4');
```

The full site uses `mysqli_db()` to retrieve this shared connection.

### Prepared queries

You already used this approach in PetStore:

```php
$stmt = $conn->prepare('SELECT Name FROM Patient WHERE PatientID = ?');
$stmt->bind_param('i', $patientId);
$stmt->execute();
$patient = $stmt->get_result()->fetch_assoc();
$stmt->close();
```

To avoid writing those same four lines throughout the site, `sql_run()` wraps `prepare()`, `bind_param()`, and `execute()`.

For example, in login.php:

```php
$stmt = sql_run(
    'SELECT UserID, Name, Email, Password, Role FROM AppUser WHERE Email = ?',
    [$email]
);
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
```

The `?` is a safe placeholder. The email is passed separately to MySQL rather than inserted into the SQL string.

The convenience functions `query_rows()`, `query_one()`, and `query_value()` use **MySQLi internally**, not PDO:
- `query_rows()`: return an array of all matching associative rows, via `fetch_all(MYSQLI_ASSOC)`.
- `query_one()`: return the first associative row, via `fetch_assoc()`.
- `query_value()`: return the first value of the first row, such as the result of `COUNT(*)`.

## 2. Files updated

| File | MySQLi role |
|---|---|
| `app/bootstrap.php` | Opens the shared MySQLi connection and provides helpers |
| `login.php` | Checks emails/password hashes with a prepared SELECT |
| `account.php` | Reads password hash and saves a changed password |
| `add_patient.php` | Prepared INSERT; uses `$conn->insert_id` for new patient ID |
| `feedback.php` | Prepared INSERT for prediction feedback |
| `api/patients.php` | Patient search, filters, pagination, JSON |
| `api/mutations.php` | Mutation search with joined tables |
| `api/genes.php` | Gene counts and cohort filter |
| `upload.php` | Upload validation, transaction, mutation INSERTs |
| Other pages and API endpoints | Use `query_rows()`, `query_one()`, or `query_value()`; those now use MySQLi |

The CSS, regular JavaScript, initial SQL dump, password1234 login setup, model weights, prediction logic and model APIs are not changed.

## 3. Transactions in upload.php

PetStore checkout used an all-or-nothing transaction to avoid a partial sale. Mutation upload uses the same principle:

```php
$conn = mysqli_db();
$conn->begin_transaction();

try {
    // Insert the sample, genes, variants and mutation observations.
    $conn->commit();
} catch (Throwable $error) {
    $conn->rollback();
    throw $error;
}
```

This prevents a partially imported mutation profile if something fails. The existing MySQL advisory lock avoids concurrent imports allocating the same unique `SourceRow`.

## 4. What was deliberately NOT modified?

- `app/model_predictor.php` and its `prediction_save(PDO $pdo, ...)` implementation.
- `api/save_prediction.php` and `api/model_input.php`.
- `assets/js/predict.js`, `assets/js/predict_page.js`.
- Any model files/weights or `database/sample_data.sql`.

`app/model_db.php` contains only the **model's original PDO connection**, extracted from the shared bootstrap file. This prevents the MySQLi conversion from breaking `prediction_save()`.

**If you later want 100% MySQLi and no PDO anywhere**, the model save function and its call site must also be rewritten. That is a separate task requiring the model team's approval and regression testing.

## 5. Local testing before merging

Run these from the project folder after switching to this branch:

```bash
php -m | grep -Ei 'mysqli|pdo_mysql'
find . -name '*.php' -print0 | xargs -0 -n1 php -l
node --check assets/js/app.js
```

Both `mysqli` and `pdo_mysql` are required **until the model is separately migrated**.

Then test in the browser with the same MySQL database:
1. Log in as Oncologist, Researcher and Patient, all using `password1234` (after applying the existing demo-password SQL once).
2. Search patients and mutations, filter genes and use the charts.
3. Add a new demonstration patient and optionally upload a photo.
4. Import `database/upload_example.tsv` using an unclaimed sample barcode. Check both successful and rejected imports.
5. Open a patient and run a model prediction. Verify that saving a prediction and submitting feedback still work.
6. Check the activity log, account password change and role permissions.
7. Compare the model-related files against the base branch to ensure they are unchanged.

**Testing limitation:** static code inspection does not replace a real end-to-end PHP/MySQL test. This branch should be treated as a review branch until the local checks pass.
