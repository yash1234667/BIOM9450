'use strict';

// Simple client-side checks. register.php repeats every check on the server.
function validateRegistration() {
    const patientId = document.getElementById('patient_id').value;
    const email = document.getElementById('email').value.trim();
    const password = document.getElementById('password').value;
    const confirm = document.getElementById('confirm_password').value;
    const error = document.getElementById('registerClientError');
    let message = '';

    if (!patientId) {
        message = 'Please select a patient ID.';
    } else if (!email || !document.getElementById('email').checkValidity()) {
        message = 'Please enter a valid email address.';
    } else if (password.length < 12 || password.length > 72) {
        message = 'Choose a password containing 12 to 72 characters.';
    } else if (password !== confirm) {
        message = 'The passwords do not match.';
    }

    if (message) {
        error.textContent = message;
        error.hidden = false;
        return false;
    }
    error.hidden = true;
    return true;
}
