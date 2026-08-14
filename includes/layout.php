<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/classes/User.php';
require_once __DIR__ . '/classes/Booking.php';
require_once __DIR__ . '/classes/Destination.php';
require_once __DIR__ . '/classes/Event.php';
require_once __DIR__ . '/classes/Schedule.php';
require_once __DIR__ . '/classes/Feedback.php';
require_once __DIR__ . '/classes/Message.php';
require_once __DIR__ . '/classes/Notification.php';
require_once __DIR__ . '/classes/ActivityLog.php';
require_once __DIR__ . '/classes/Payment.php';
require_once __DIR__ . '/classes/GuidePayout.php';
start_session();

function render_page(string $role, string $active_page, string $page_title, callable $content_fn): void
{
    $GLOBALS['page_title'] = $page_title;
    include __DIR__ . '/header.php';
    include __DIR__ . '/sidebar.php';
    ?>
    <div class="content-wrapper">
        <div class="container-fluid py-4">
            <?php $content_fn(); ?>
        </div>
    </div>
    <?php
    include __DIR__ . '/footer.php';
}
