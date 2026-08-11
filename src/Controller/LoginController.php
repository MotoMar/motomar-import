<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Tire\RowField;
use App\Request;
use App\Auth;
use App\Bootstrap;
use App\Csrf;

final class LoginController
{
    public function show(): void
    {
        if (Auth::check()) {
            $this->redirect('');
            return;
        }

        $title = 'Logowanie';
        ob_start();
        $content = ob_get_clean();
        require dirname(__DIR__, 2) . '/templates/login.php';
    }

    public function handle(): void
    {
        if (!Csrf::validate(Request::post('_csrf'))) {
            Bootstrap::logger()->warning('CSRF validation failed', ['endpoint' => '/login']);
            $_SESSION['_flash_error'] = 'Nieprawidłowy token bezpieczeństwa. Odśwież stronę i spróbuj ponownie.';
            $this->redirect('login');
            return;
        }

        $email    = trim(Request::post('email'));
        $password = Request::post('password');

        $user = Bootstrap::db()->get(
            'users',
            ['id', 'email', 'hashed_password'],
            ['email' => $email]
        );
        $user = is_array($user) ? $user : null;

        if ($user === null || !password_verify($password, RowField::text($user, 'hashed_password'))) {
            Bootstrap::logger()->warning('Failed login attempt', ['email' => $email]);
            $_SESSION['_flash_error'] = 'Nieprawidłowy e-mail lub hasło.';
            $this->redirect('login');
            return;
        }

        $userId = RowField::integer($user, 'id');
        $userEmail = RowField::text($user, 'email');

        Auth::login($userId, $userEmail);
        Bootstrap::logger()->info('User logged in', ['id' => $userId, 'email' => $userEmail]);
        $this->redirect('');
    }

    public function logout(): void
    {
        $email = Auth::email();
        Auth::logout();
        Bootstrap::logger()->info('User logged out', ['email' => $email]);
        $this->redirect('login');
    }

    private function redirect(string $path): void
    {
        $base = rtrim(dirname(Request::server('SCRIPT_NAME')), '/');
        header('Location: ' . $base . '/' . ltrim($path, '/'));
        exit;
    }
}
