# OncoAtlas: beginner-friendly code guide (PetStore style)

This guide accompanies branch **feature/petstore-style-simplified-website**, based on **feature/fixed-demo-password1234**.

## What was changed?

The aim is the **same functioning website with clearer code**, not a new database or new model. The PHP/HTML/CSS design is preserved, the three demonstration accounts still use the fixed password \`password1234\` after running \`database/demo_password1234.sql\`, and the original \`database/sample_data.sql\` is unchanged.

The key teaching style is taken from your PetStore assignment:

- \`apps/db.php\` in PetStore ↔ \`app/bootstrap.php\` here: one shared MySQL connection and helper functions.
- \`apps/layout.php\` in PetStore ↔ \`app/layout.php\` here: reusable header, navigation, main content, footer.
- \`add_animal.php\` in PetStore ↔ \`add_patient.php\` here: one PHP page handles GET (display form) and POST (validate and insert).
- \`search_animals.php\` in PetStore ↔ \`patients.php\` and \`api/patients.php\` here: read filters, build \`WHERE\`, execute prepared SQL, display matches.
- \`apps/customer_api.php\` in PetStore ↔ \`api/patients.php\`, \`api/mutations.php\`, \`api/genes.php\` here: send JSON to a JavaScript page using AJAX.
- \`apps/dashboard_data.php\` in PetStore ↔ \`api/stats.php\` here: calculate SQL totals and draw charts with JavaScript.
- PetStore photo uploads ↔ the optional patient photo in \`add_patient.php\`.
- The mutation TSV importer has extra validation because it adds several linked SQL records together.

## Recommended reading order

| Group | Files | What to explain in your demonstration |
|---|---|---|
| 1. Setup | \`config/config.php\`, \`app/bootstrap.php\` | How PDO connects to MySQL; sessions; prepared statements and roles |
| 2. Shared HTML | \`app/layout.php\`, \`assets/css/style.css\` | Header/sidebar/footer functions, CSS classes and mobile design |
| 3. Login | \`login.php\`, \`logout.php\`, \`database/demo_password1234.sql\` | \`POST\`, \`SELECT\`, \`password_verify()\`, sessions and logging out |
| 4. Dashboard | \`dashboard.php\`, \`api/stats.php\`, \`assets/js/app.js\` | Counts, GROUP BY, JSON and charts |
| 5. Patients | \`patients.php\`, \`api/patients.php\`, \`patient.php\`, \`add_patient.php\` | GET filters, POST validation, JOINs, profile links, image uploads |
| 6. Mutations | \`mutations.php\`, \`api/mutations.php\`, \`genes.php\`, \`api/genes.php\` | Many-to-one relationships and filterable mutation records |
| 7. Import | \`upload.php\`, \`api/patient_samples.php\` | \`$_FILES\`, TSV column validation, prepared inserts and rollback |
| 8. Other views | \`cohorts.php\`, \`professionals.php\`, \`analytics.php\`, \`account.php\`, \`activity.php\` | Cancer type comparisons and researcher vs oncologist permissions |
| 9. Prediction/model | Existing model and prediction files | **Unchanged in this branch**; owned by the model integration team |

## Example 1: how a PHP form works

\`\`\`php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // The browser submitted the form.
    $name = trim($_POST['name'] ?? '');

    // Check the user input before inserting it.
    if ($name === '') {
        $error = 'Please enter a name.';
    } else {
        // Use ? rather than joining user input into the SQL query.
        $stmt = db()->prepare('INSERT INTO Example (Name) VALUES (?)');
        $stmt->execute([$name]);
    }
}
\`\`\`

In \`add_patient.php\`, the same steps are longer only because the patient form includes more fields and an optional photo.

## Example 2: how AJAX works

1. You type **TP53** into \`mutations.php\`.
2. \`assets/js/app.js\` reads the search form and constructs a URL such as \`api/mutations.php?gene=TP53&page=1\`.
3. JavaScript calls \`fetch(url)\`.
4. \`api/mutations.php\` validates the value, runs prepared SQL with the gene filter and joins SampleMutation, Gene, Variant, TumorSample and Patient.
5. PHP calls \`json_response()\`, sending \`rows\`, \`total\`, \`page\` and \`pages\`.
6. JavaScript reads the JSON and replaces the table rows **without reloading the page**.

The same pattern is used for patient search, gene search, charts and existing-sample lookup.

## Example 3: why the database needs several tables

- \`CancerType\`: one cancer type has many \`Patient\` rows.
- \`Patient\`: one patient has many \`TumorSample\` rows.
- \`TumorSample\`: one sample has many \`SampleMutation\` observations.
- \`SampleMutation\`: each record links one \`Gene\` and one \`Variant\`.
- \`AppUser\`: holds the hashed password and role, while \`Professional\` stores clinician information.
- \`Prediction\`, \`PredictionScore\`, \`PredictionMutation\` and \`Feedback\` belong to the model integration area and were not changed.

This avoids repeating patient information on every mutation row and illustrates database normalization.

## Key terms and lines to explain

- \`require_once\`: loads the shared PHP file one time.
- \`$_GET\`: reads URL query parameters used for filters.
- \`$_POST\`: reads values from a submitted HTML form.
- \`$_FILES\`: contains the uploaded file's temporary path, size and error status.
- \`$stmt = db()->prepare($sql)\`: asks PDO to prepare SQL with placeholders.
- \`$stmt->execute([$id])\`: supplies the values for those placeholders.
- \`JOIN ... ON ...\`: connects two tables through their keys.
- \`GROUP BY\` / \`COUNT\`: creates totals for the dashboard and charts.
- \`json_response(...)\`: converts PHP arrays to JSON for AJAX.
- \`password_verify()\`: checks a typed password against a stored bcrypt hash.
- \`$_SESSION['account']\`: remembers the logged-in user across pages.
- \`csrf_field()\` / \`verify_csrf()\`: help stop other websites submitting forms using the current session.
- \`htmlspecialchars()\` through \`h()\`: prevents database text from being interpreted as HTML.
- \`beginTransaction()\`, \`commit()\`, \`rollBack()\`: ensure multiple related INSERTs succeed together.

## What is intentionally still more complex than PetStore?

Mutation imports are inherently more complicated: one input row may require identifying a gene, identifying a genomic variant, ensuring the tumour sample belongs to the selected patient, and inserting the mutation. Transactions and duplicate checks protect the research database. They are **not** removed merely to make the code shorter.

Similarly, the project keeps **PDO** instead of rewriting all pages to MySQLi because the prediction integration depends on the existing PDO connection.

The JavaScript still includes response-order checks, escaping of displayed data, and pagination; these are explained in comments rather than removed.

## Local test checklist (before merging)

Run with your own PHP/MySQL server:

1. Import the original database only if not already imported; run \`database/demo_password1234.sql\` once if necessary.
2. Log in to all three roles using \`password1234\`.
3. Verify the Patient explorer loads, searches and paginates.
4. Open a patient's profile and check samples/mutations.
5. Try Add patient with valid and invalid fields, and optional photo.
6. Filter mutations by gene, chromosome and cancer cohort; try gene links.
7. Open Researcher analytics and compare TP53; confirm Oncologist cannot access analytics.
8. Import \`database/upload_example.tsv\` into a new demonstration sample. Test malformed input, wrong sample barcode and duplicate rows.
9. Verify logout prevents access to protected pages.
10. Compare the model and prediction files to the base branch: they must be identical.

**Testing status:** the source has been revised and reviewed for consistent endpoints/field names, but the full PHP + MySQL workflow must be tested on your local database before merging. The original SQL and model files are not modified in this branch.
