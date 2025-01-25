// Start session securely for custom logic
function start_custom_session() {
    if (!session_id()) {
        session_start();
    }
}
add_action('init', 'start_custom_session', 1);

// Use output buffering at the start of the request
ob_start();

// Custom 2FA authentication logic
function custom_2fa_authentication($user, $username, $password) {
    if (is_wp_error($user)) {
        return $user;
    }

    // Check if the email exists
    if (!email_exists($username)) {
        return new WP_Error('email_not_found', __('Email not found.'));
    }

    $user = get_user_by('email', $username);

    // Check password validity
    if (!wp_check_password($password, $user->user_pass, $user->ID)) {
        return new WP_Error('incorrect_password', __('Incorrect password.'));
    }

    // Apply 2FA for "customer" role
    if (in_array('customer', (array) $user->roles)) {
        if (!isset($_POST['2fa_code'])) {
            $code = rand(100000, 999999);
            update_user_meta($user->ID, '2fa_code', $code);

            // Send the 2FA code via email
            wp_mail(
                $user->user_email,
                'Two-Factor Authentication Code',
                "Your login code is: $code"
            );

            $_SESSION['2fa_user_id'] = $user->ID;  // Set session variable

            // Redirect to the 2FA verification page
            wp_redirect(site_url('/2fa-verification'));
            exit;
        }
    }

    return $user;
}
add_filter('authenticate', 'custom_2fa_authentication', 30, 3);

// 2FA verification form shortcode
function two_factor_auth_shortcode() {
    ob_start();

    if (is_page('2fa-verification')) {
        if (isset($_POST['2fa_code'])) {
            if (isset($_SESSION['2fa_user_id'])) {
                $user_id = $_SESSION['2fa_user_id'];
                $saved_code = get_user_meta($user_id, '2fa_code', true);
                $entered_code = sanitize_text_field($_POST['2fa_code']);

                if ($entered_code === $saved_code) {
                    // Clear the code and session
                    delete_user_meta($user_id, '2fa_code');
                    unset($_SESSION['2fa_user_id']); // Clear session after verification

                    // Log the user in
                    wp_set_current_user($user_id);
                    wp_set_auth_cookie($user_id);

                    // Redirect to the account page after successful login
                    wp_redirect(home_url('/my-account/'));
                    exit;
                } else {
                    echo '<p style="color: red;">Incorrect 2FA code. Please try again.</p>';
                }
            } else {
                echo '<p style="color: red;">Session expired. Please log in again.</p>';
            }
        }

        // Display the 2FA form
        ?>
        <div class="two-factor-auth-container">
            <div class="two-factor-auth-form">
                <img src="https://rewardhub.com.au/wp-content/uploads/2024/11/Logo-7.png" alt="2FA Icon" style="max-width: 50px; margin-bottom: 10px;">
                <h2>Two-Factor Authentication</h2>
                <form method="POST">
                    <p>Please enter the 2FA code sent to your email:</p>
                    <input type="text" class="input" name="2fa_code" required />
                    <input type="submit" class="button button-primary button-large" value="Verify Code" />
                </form>
            </div>
        </div>
        <?php
    } else {
        echo '<p style="color: red;">Invalid session or page access.</p>';
    }

    return ob_get_clean();
}
add_shortcode('two_factor_auth_form', 'two_factor_auth_shortcode');

// Redirect on login error
function custom_login_error_redirect($redirect_to, $request, $user) {
    if (is_wp_error($user)) {
        $error_code = $user->get_error_code();
        $redirect_url = home_url('/login/');

        if ($error_code === 'incorrect_password') {
            $redirect_url = add_query_arg('error', 'incorrect_password', $redirect_url);
        } elseif ($error_code === 'email_not_found') {
            $redirect_url = add_query_arg('error', 'email_not_found', $redirect_url);
        }

        wp_redirect($redirect_url);
        exit;
    }

    return $redirect_to;
}
add_filter('login_redirect', 'custom_login_error_redirect', 10, 3);

// Clear user meta on logout
function clear_2fa_meta_on_logout() {
    $user_id = get_current_user_id();
    if ($user_id) {
        delete_user_meta($user_id, '2fa_code');
    }
}
add_action('wp_logout', 'clear_2fa_meta_on_logout');

// Front-end login form shortcode
function custom_login_form() {
    ob_start();
    ?>
    <form method="post" action="<?php echo esc_url(site_url('wp-login.php')); ?>">
        <label for="username">Email Address</label>
        <input type="text" name="log" id="username" required />

        <label for="password">Password</label>
        <input type="password" name="pwd" id="password" required />

        <input type="submit" value="Login" />
    </form>
    <?php
    return ob_get_clean();
}
add_shortcode('custom_login_form', 'custom_login_form');
