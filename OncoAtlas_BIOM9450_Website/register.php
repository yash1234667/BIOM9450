<?php
require_once __DIR__.'/app/bootstrap.php';

// Registration is intended for the existing synthetic teaching profiles only.
if (signed_in()) redirect('dashboard.php');

$error = '';
$patients = [];
$selectedId = $_POST['patient_id'] ?? '';
$emailValue = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = null;
    try {
        verify_csrf();

        $patientId = int_param($_POST['patient_id'] ?? null);
        $email = strtolower($emailValue);
        $password = $_POST['password'] ?? null;
        $confirm = $_POST['confirm_password'] ?? null;

        if (!$patientId) throw new RuntimeException('Please select an existing patient ID.');
        if (strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Please enter a valid email address.');
        }
        if (!is_string($password) || strlen($password) < 12 || strlen($password) > 72) {
            throw new RuntimeException('Use a password between 12 and 72 characters.');
        }
        if (!is_string($confirm) || $password !== $confirm) {
            throw new RuntimeException('The passwords do not match.');
        }

        $pdo = db();
        $pdo->beginTransaction();

        // Lock this patient's row so two accounts cannot claim it at once.
        $stmt = $pdo->prepare('SELECT PatientID, Name, UserID
                               FROM Patient WHERE PatientID = ? FOR UPDATE');
        $stmt->execute([$patientId]);
        $patient = $stmt->fetch();

        if (!$patient) throw new RuntimeException('This patient ID does not exist.');
        if ($patient['UserID'] !== null) {
            throw new RuntimeException('This patient ID already has an account.');
        }

        // The unique index on AppUser.Email also rejects simultaneous duplicates.
        $stmt = $pdo->prepare('SELECT UserID FROM AppUser WHERE Email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) throw new RuntimeException('That email is already registered.');

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("INSERT INTO AppUser (Name, Email, Password, Role)
                               VALUES (?, ?, ?, 'Patient')");
        $stmt->execute([$patient['Name'], $email, $hash]);
        $userId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare('UPDATE Patient SET UserID = ?
                               WHERE PatientID = ? AND UserID IS NULL');
        $stmt->execute([$userId, $patientId]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('Unable to link the patient profile.');

        $stmt = $pdo->prepare('INSERT INTO AuditLog (UserID, Action, EntityType, EntityID)
                               VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, 'REGISTER', 'Patient', (string)$patientId]);

        $pdo->commit();
        flash('Account created successfully. You can now sign in.');
        redirect('login.php');
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
        error_log('Registration error: '.$e->getMessage());
        $error = $e instanceof PDOException
            ? 'Unable to register this account. Check whether the email is already in use.'
            : $e->getMessage();
    }
}

// Only unclaimed records are available for registration.
try {
    $patients = query_rows('SELECT p.PatientID, p.Name, p.TCGAPatientID, c.StudyCode
                            FROM Patient p
                            LEFT JOIN CancerType c ON c.CancerTypeID = p.CancerTypeID
                            WHERE p.UserID IS NULL
                            ORDER BY p.PatientID');
} catch (Throwable $e) {
    error_log('Registration patient list: '.$e->getMessage());
    $error = 'Cannot load patient IDs. Check the MySQL connection.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Create patient account · OncoAtlas</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/registration.js" defer></script>
</head>
<body class="login-page">
<div class="login-grid">
    <section class="login-art">
        <a href="index.php" class="brand login-brand">
            <span class="brand-symbol">✳</span>
            <span><strong>onco<span>atlas.</span></strong><small>CANCER GENOMICS</small></span>
        </a>
        <div class="orb orb-one"></div><div class="orb orb-two"></div>
        <div class="login-story">
            <div class="story-kicker"><span class="live-dot"></span> TCGA PANCANCER ATLAS</div>
            <h1>Your research<br>profile, <em>connected.</em></h1>
            <p>Create a demonstration patient account to explore your linked mutation records and saved predictions.</p>
            <div class="story-metrics">
                <div><b>06</b><span>Cancer cohorts</span></div>
                <div><b>01</b><span>Linked patient profile</span></div>
            </div>
        </div>
        <small class="story-bottom">BIOM9450 · Academic demonstration</small>
    </section>

    <section class="login-form-side">
        <div class="signin-box register-box">
            <span class="signin-mark">✳</span>
            <span class="overline">PATIENT ACCESS</span>
            <h2>Create an account</h2>
            <p>Select an existing demonstration patient and choose your login details.</p>

            <?php if ($error): ?>
                <div class="notice error" role="alert"><?=h($error)?></div>
            <?php endif; ?>
            <div id="registerClientError" class="notice error" role="alert" hidden></div>

            <?php if ($patients): ?>
            <form method="post" id="registrationForm" class="form-stack" onsubmit="return validateRegistration()">
                <?=csrf_field()?>
                <label for="patient_id">Patient ID</label>
                <select name="patient_id" id="patient_id" required>
                    <option value="">Select your patient record</option>
                    <?php foreach ($patients as $patient): ?>
                        <option value="<?=(int)$patient['PatientID']?>"
                            <?=((string)$selectedId === (string)$patient['PatientID']) ? 'selected' : ''?>>
                            <?=h('#'.$patient['PatientID'].' · '.$patient['Name'].' · '.($patient['TCGAPatientID'] ?: 'Manual profile').' · '.strtoupper((string)$patient['StudyCode']))?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="registration-note">Only patient records without accounts are listed. This is a teaching demonstration; real clinical records require identity verification.</p>

                <label for="email">Email address</label>
                <input type="email" id="email" name="email" maxlength="255" value="<?=h($emailValue)?>"
                       autocomplete="email" placeholder="you@example.com" required>

                <label for="password">Password</label>
                <input type="password" id="password" name="password" minlength="12" maxlength="72"
                       autocomplete="new-password" placeholder="At least 12 characters" required>

                <label for="confirm_password">Confirm password</label>
                <input type="password" id="confirm_password" name="confirm_password" minlength="12" maxlength="72"
                       autocomplete="new-password" placeholder="Enter your password again" required>

                <button class="button primary button-wide" type="submit">Create patient account <span>→</span></button>
            </form>
            <?php else: ?>
                <div class="notice">No unclaimed demonstration profiles are available.</div>
            <?php endif; ?>

            <div class="sign-divider">ALREADY REGISTERED?</div>
            <p class="login-help"><a class="registration-link" href="login.php">Sign in to your account</a></p>
            <p class="login-disclaimer">Demonstration patient demographics are synthetic. Not for clinical decisions.</p>
        </div>
    </section>
</div>
</body>
</html>
