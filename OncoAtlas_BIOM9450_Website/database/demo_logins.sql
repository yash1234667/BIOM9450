-- OncoAtlas local demo login reset (optional; run once if existing hashes are unknown).
-- Run AFTER importing database/sample_data.sql.
-- All three teaching-only accounts then use password: password
-- Do not use these credentials on an internet-accessible deployment.
USE cancer_portal;

UPDATE AppUser
SET Password = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi'
WHERE Email IN (
    'oncologist@example.test',
    'researcher@example.test',
    'patient@example.test'
);
