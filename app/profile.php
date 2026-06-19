<?php

declare(strict_types=1);

/**
 * Public user profile page.
 *
 * This page allows a verified public user to manage their personal details.
 * The email address is the identity key and may not be changed here.
 */

if ($path !== '/profile') {
    return;
}

if (!function_exists('access_gate_is_verified') || !access_gate_is_verified($config)) {
    http_response_code(403);

    render_layout(
        $config,
        'Profile',
        'Please request access before editing your profile.',
        '<section class="search-page"><h1>Profile</h1><p>Please request access before editing your profile.</p></section>',
        '/profile'
    );

    exit();
}

$email = mb_strtolower(trim((string)($_SESSION['manual_access_email'] ?? '')));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(403);

    render_layout(
        $config,
        'Profile',
        'No verified email address found.',
        '<section class="search-page"><h1>Profile</h1><p>No verified email address found.</p></section>',
        '/profile'
    );

    exit();
}

/**
 * Make sure the current verified email exists in the public user table.
 */
if (function_exists('public_user_ensure')) {
    public_user_ensure(
        $pdo,
        $email,
        'profile_view',
        function_exists('access_gate_ip_address')
            ? access_gate_ip_address()
            : (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        function_exists('access_gate_user_agent')
            ? access_gate_user_agent()
            : (string)($_SERVER['HTTP_USER_AGENT'] ?? '')
    );
}

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $firstName = (string)($_POST['first_name'] ?? '');
    $lastName = (string)($_POST['last_name'] ?? '');
    $phone = (string)($_POST['phone'] ?? '');

    try {
        if (!function_exists('public_user_update_profile')) {
            throw new RuntimeException('public_user_update_profile() is not available.');
        }

        public_user_update_profile(
            $pdo,
            $email,
            $firstName,
            $lastName,
            $phone
        );

        $message = 'Your profile has been updated.';
    } catch (Throwable $e) {
        error_log('Public profile update failed: ' . $e->getMessage());
        $error = 'Your profile could not be updated. Please try again later.';
    }
}

$user = function_exists('public_user_get_by_email')
    ? public_user_get_by_email($pdo, $email)
    : null;

$firstName = (string)($user['first_name'] ?? '');
$lastName = (string)($user['last_name'] ?? '');
$phone = (string)($user['phone'] ?? '');

$content = '<section class="search-page">';
$content .= '<h1>My profile</h1>';
$content .= '<p class="lead">Manage your personal user details.</p>';

if ($message !== '') {
    $content .= '<div class="profile-success-message">✓ ' . e($message) . '</div>';
}

if ($error !== '') {
    $content .= '<div class="notice notice-error">' . e($error) . '</div>';
}

$content .= '<form class="admin-form" method="post" action="/profile">';

/**
 * The email address is intentionally readonly.
 *
 * Do not add name="email".
 * The email is the identity key and must come from the verified session,
 * not from user-submitted POST data.
 */
$content .= '<label class="admin-form-field">';
$content .= '<span>Email address</span>';
$content .= '<input type="email" value="' . e($email) . '" readonly>';
$content .= '<small>This email address is used as your login identity and cannot be changed here.</small>';
$content .= '</label>';

$content .= '<label class="admin-form-field">';
$content .= '<span>First name</span>';
$content .= '<input type="text" name="first_name" value="' . e($firstName) . '" maxlength="120" autocomplete="given-name">';
$content .= '</label>';

$content .= '<label class="admin-form-field">';
$content .= '<span>Last name</span>';
$content .= '<input type="text" name="last_name" value="' . e($lastName) . '" maxlength="120" autocomplete="family-name">';
$content .= '</label>';

$content .= '<label class="admin-form-field">';
$content .= '<span>Phone number</span>';
$content .= '<input type="tel" name="phone" value="' . e($phone) . '" maxlength="80" autocomplete="tel">';
$content .= '</label>';

$content .= '<div class="profile-form-actions">';
$content .= '<button type="submit">Save profile</button>';
$content .= '</div>';
$content .= '</form>';

$content .= '</section>';

render_layout(
    $config,
    'My profile',
    'Manage your public CoCoS user profile.',
    $content,
    '/profile'
);

exit();
