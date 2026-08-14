<?php
$page_title = 'Home';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
start_session();

$db = Database::getInstance()->getConnection();
$featured_dests = $db->query("SELECT d.id, d.name, d.location, d.description, d.image, d.entrance_fee, d.difficulty, d.category, (SELECT COUNT(*) FROM destination_reviews r WHERE r.destination_id = d.id) as review_count, (SELECT COALESCE(AVG(r.rating),0) FROM destination_reviews r WHERE r.destination_id = d.id) as avg_rating FROM destinations d WHERE d.status = 'active' ORDER BY d.featured DESC, d.created_at DESC LIMIT 6")->fetchAll();
$total_dests = (int)$db->query("SELECT COUNT(*) FROM destinations WHERE status='active'")->fetchColumn();
$total_reviews = (int)$db->query("SELECT COUNT(*) FROM destination_reviews")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="base-url" content="<?= BASE_URL ?>">
    <title>BINALGO - Explore Binalbagan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/landing.css" rel="stylesheet">
</head>
<body style="background:#fff;">

<!-- Topbar -->
<nav class="landing-topbar" id="landingTopbar">
    <a href="<?= BASE_URL ?>/" class="brand">
        <i class="fas fa-map-marked-alt"></i>BINALGO
    </a>
    <div class="nav-actions">
        <?php if (is_logged_in()): ?>
            <?php $role = $_SESSION['role'] ?? 'tourist'; ?>
            <a href="<?= BASE_URL ?>/<?= $role ?>/index.php" class="btn btn-primary">Dashboard</a>
        <?php else: ?>
            <a href="<?= BASE_URL ?>/auth/login.php" class="btn btn-outline-primary">Login</a>
            <a href="<?= BASE_URL ?>/auth/register.php" class="btn btn-primary">Register</a>
        <?php endif; ?>
    </div>
</nav>

<!-- Flash Messages -->
<?php
$_flash = get_flash_message();
if ($_flash):
    $_alert_type = $_flash['type'] === 'error' ? 'alert-danger' : ($_flash['type'] === 'success' ? 'alert-success' : 'alert-info');
    $_icon = $_flash['type'] === 'error' ? 'fa-exclamation-circle' : ($_flash['type'] === 'success' ? 'fa-check-circle' : 'fa-info-circle');
?>
<div class="position-fixed" style="top:80px;right:24px;z-index:9999;max-width:420px;width:100%;">
    <div class="alert <?= $_alert_type ?> alert-dismissible fade show shadow-sm d-flex align-items-center" role="alert" style="border-radius:12px;">
        <i class="fas <?= $_icon ?> me-2"></i>
        <span><?= sanitize($_flash['message']) ?></span>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
</div>
<?php endif; ?>

<!-- Hero Section -->
<section class="hero-section" id="hero">
    <div class="hero-bg"></div>
    <div class="hero-bg-overlay"></div>
    <canvas class="hero-3d-canvas" id="heroCanvas"></canvas>
    <div class="hero-particles">
        <div class="hero-particle"></div><div class="hero-particle"></div><div class="hero-particle"></div>
        <div class="hero-particle"></div><div class="hero-particle"></div><div class="hero-particle"></div>
    </div>
    <div class="container hero-content">
        <div class="row justify-content-center">
            <div class="col-lg-8 text-center">
                <h1 class="text-white">Explore the Beauty of<br><span class="highlight">Binalbagan</span></h1>
                <p class="hero-sub">Discover the oldest town in Negros Occidental — from pristine waterfalls and lush mangroves to rich heritage sites. Book tours and experience Binalbagan like never before.</p>
                <div class="d-flex gap-3 justify-content-center flex-wrap">
                    <a href="#featured" class="btn btn-hero-primary" data-scroll>
                        <i class="fas fa-compass me-2"></i>Explore Destinations
                    </a>
                    <a href="<?= BASE_URL ?>/auth/register.php" class="btn btn-hero-outline">
                        <i class="fas fa-user-plus me-2"></i>Create Account
                    </a>
                </div>
            </div>
        </div>
        <!-- Floating Glass Stats Card -->
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="hero-glass-card">
                    <div class="d-flex align-items-center justify-content-center flex-wrap gap-2">
                        <div class="stat-item">
                            <div class="stat-num" data-counter="<?= $total_dests ?>" data-suffix="+">0</div>
                            <div class="stat-label">Destinations</div>
                        </div>
                        <div class="stat-divider d-none d-md-block"></div>
                        <div class="stat-item">
                            <div class="stat-num" data-counter="<?= $total_reviews ?>" data-suffix="+">0</div>
                            <div class="stat-label">Happy Tourists</div>
                        </div>
                        <div class="stat-divider d-none d-md-block"></div>
                        <div class="stat-item">
                            <div class="stat-num" data-counter="4.9" data-decimals="1">0</div>
                            <div class="stat-label"><i class="fas fa-star"></i> Average Rating</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Featured Destinations -->
<?php if (!empty($featured_dests)): ?>
<section class="featured-section" id="featured">
    <div class="container">
        <div class="section-header reveal">
            <div class="section-badge"><i class="fas fa-fire me-1"></i> Popular</div>
            <h2>Featured Destinations</h2>
            <p>Explore the most loved spots in Binalbagan, handpicked for you.</p>
        </div>
        <div class="row g-4">
            <?php
            $diffColors = ['easy' => '#10b981', 'moderate' => '#f59e0b', 'difficult' => '#ef4444', 'extreme' => '#dc2626'];
            $i = 0;
            foreach ($featured_dests as $fd):
                $diffColor = $diffColors[$fd['difficulty']] ?? '#64748b';
                $fdImg = $fd['image'] ? BASE_URL . '/uploads/destinations/' . $fd['image'] : BASE_URL . '/assets/images/bambi.jpg';
                $avgR = round((float)$fd['avg_rating'], 1);
                $feeText = $fd['entrance_fee'] > 0 ? '₱' . number_format($fd['entrance_fee'], 0) : 'Free';
                $desc = trim((string)($fd['description'] ?? ''));
                if (strlen($desc) > 180) $desc = substr($desc, 0, 177) . '...';
                $detailUrl = BASE_URL . '/tourist/destination_detail.php?id=' . $fd['id'];
            ?>
            <div class="col-12 col-sm-6 col-lg-4 reveal reveal-delay-<?= $i % 4 ?>">
                <article class="dest-card" data-id="<?= (int)$fd['id'] ?>"
                         data-name="<?= sanitize($fd['name']) ?>"
                         data-loc="<?= sanitize($fd['location']) ?>"
                         data-img="<?= $fdImg ?>"
                         data-diff="<?= strtoupper($fd['difficulty']) ?>"
                         data-diff-color="<?= $diffColor ?>"
                         data-fee="<?= $feeText ?>"
                         data-rating="<?= $avgR ?>"
                         data-reviews="<?= (int)$fd['review_count'] ?>"
                         data-desc="<?= sanitize($desc) ?>"
                         data-detail="<?= $detailUrl ?>">
                    <div class="dest-img-wrap">
                        <img src="<?= $fdImg ?>" alt="<?= sanitize($fd['name']) ?>" class="dest-img" loading="lazy">
                        <button type="button" class="dest-bookmark" data-id="<?= (int)$fd['id'] ?>" aria-label="Save to wishlist" title="Save to wishlist">
                            <i class="far fa-heart" aria-hidden="true"></i>
                        </button>
                        <span class="dest-badge" style="background:<?= $diffColor ?>;"><?= ucfirst($fd['difficulty']) ?></span>
                        <span class="dest-price"><?= $feeText ?></span>
                        <button type="button" class="dest-quickview" aria-label="Quick preview">
                            <i class="fas fa-eye" aria-hidden="true"></i> Quick Preview
                        </button>
                    </div>
                    <div class="dest-body">
                        <h6><?= sanitize($fd['name']) ?></h6>
                        <div class="dest-loc"><i class="fas fa-map-pin"></i><?= sanitize($fd['location']) ?></div>
                        <div class="dest-meta">
                            <?php if ($avgR > 0): ?>
                                <span class="rating"><i class="fas fa-star"></i> <?= $avgR ?></span>
                                <span>(<?= $fd['review_count'] ?> review<?= $fd['review_count'] == 1 ? '' : 's' ?>)</span>
                            <?php else: ?>
                                <span>New</span>
                            <?php endif; ?>
                            <a href="<?= $detailUrl ?>" class="dest-view-link">View <i class="fas fa-chevron-right"></i></a>
                        </div>
                    </div>
                </article>
            </div>
            <?php $i++; endforeach; ?>
        </div>
        <div class="text-center mt-5 reveal">
            <a href="<?= BASE_URL ?>/tourist/destinations.php" class="btn btn-outline-primary" style="border-radius:12px;font-weight:600;padding:12px 32px;min-height:52px;">
                View All Destinations <i class="fas fa-arrow-right ms-2"></i>
            </a>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Features Section -->
<section class="features-section">
    <div class="container">
        <div class="section-header reveal">
            <div class="section-badge">Why Choose Us</div>
            <h2>Why Choose BINALGO?</h2>
            <p>Everything you need for a seamless tour experience, from booking to feedback.</p>
        </div>
        <div class="row g-4">
            <div class="col-lg-3 col-md-6 reveal reveal-delay-0">
                <div class="feature-card" style="--card-accent:#0c6e5e;">
                    <div class="feature-icon" style="background:rgba(12,110,94,0.1);color:#0c6e5e;">
                        <i class="fas fa-route"></i>
                    </div>
                    <h5>Curated Tours</h5>
                    <p>Discover hand-picked destinations with detailed itineraries and immersive experiences.</p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 reveal reveal-delay-1">
                <div class="feature-card" style="--card-accent:#f59e0b;">
                    <div class="feature-icon" style="background:rgba(245,158,11,0.1);color:#f59e0b;">
                        <i class="fas fa-shield-halved"></i>
                    </div>
                    <h5>Safe & Secure</h5>
                    <p>Travel with confidence knowing every destination follows safety protocols and guidelines.</p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 reveal reveal-delay-2">
                <div class="feature-card" style="--card-accent:#ef4444;">
                    <div class="feature-icon" style="background:rgba(239,68,68,0.1);color:#ef4444;">
                        <i class="fas fa-star"></i>
                    </div>
                    <h5>Verified Reviews</h5>
                    <p>Read honest feedback from fellow travelers to choose the perfect tour every time.</p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 reveal reveal-delay-3">
                <div class="feature-card" style="--card-accent:#10b981;">
                    <div class="feature-icon" style="background:rgba(16,185,129,0.1);color:#10b981;">
                        <i class="fas fa-bolt"></i>
                    </div>
                    <h5>Instant Booking</h5>
                    <p>Book your favorite destinations instantly with secure online payment options.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- How It Works -->
<section class="how-it-works">
    <div class="container">
        <div class="section-header reveal">
            <div class="section-badge">Simple Process</div>
            <h2>How It Works</h2>
            <p>Get started in three simple steps.</p>
        </div>
        <div class="row g-4 step-timeline">
            <div class="col-md-4 reveal reveal-delay-0">
                <div class="step-card">
                    <div class="step-number"><i class="fas fa-compass"></i><span class="step-badge">Step 01</span></div>
                    <h5>Discover Spots</h5>
                    <p>Browse handpicked destinations, tours, and events across Binalbagan.</p>
                </div>
            </div>
            <div class="col-md-4 reveal reveal-delay-1">
                <div class="step-card">
                    <div class="step-number"><i class="fas fa-calendar-check"></i><span class="step-badge">Step 02</span></div>
                    <h5>Book Tour / Guide</h5>
                    <p>Choose your preferred schedule and book instantly with secure payment.</p>
                </div>
            </div>
            <div class="col-md-4 reveal reveal-delay-2">
                <div class="step-card">
                    <div class="step-number"><i class="fas fa-face-smile"></i><span class="step-badge">Step 03</span></div>
                    <h5>Enjoy Binalbagan</h5>
                    <p>Experience the tour, then share feedback to help fellow travelers decide.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CTA Section -->
<section class="cta-section">
    <div class="container reveal">
        <h2>Ready to Start Your Adventure?</h2>
        <p>Join thousands of travelers using BINALGO to create unforgettable experiences in Binalbagan.</p>
        <div class="d-flex gap-3 justify-content-center flex-wrap">
            <a href="<?= BASE_URL ?>/auth/register.php" class="btn btn-light btn-lg">
                <i class="fas fa-user-plus me-2"></i>Get Started Free
            </a>
            <a href="<?= BASE_URL ?>/auth/login.php" class="btn btn-outline-light btn-lg">
                <i class="fas fa-sign-in-alt me-2"></i>Sign In
            </a>
        </div>
    </div>
</section>

<!-- Footer -->
<footer class="landing-footer">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-4 col-md-6">
                <a href="<?= BASE_URL ?>/" class="footer-brand">
                    <i class="fas fa-map-marked-alt"></i>BINALGO
                </a>
                <p class="footer-desc">Discover Binalbagan — the oldest town in Negros Occidental. Explore pristine waterfalls, lush mangroves, rich heritage, and create unforgettable memories.</p>
                <div class="footer-social mt-3">
                    <a href="#" title="Facebook"><i class="fab fa-facebook-f"></i></a>
                    <a href="#" title="Instagram"><i class="fab fa-instagram"></i></a>
                    <a href="#" title="Twitter"><i class="fab fa-x-twitter"></i></a>
                    <a href="#" title="YouTube"><i class="fab fa-youtube"></i></a>
                </div>
            </div>
            <div class="col-lg-2 col-md-6">
                <h6 class="footer-heading">Explore</h6>
                <ul class="footer-links">
                    <li><a href="<?= BASE_URL ?>/tourist/destinations.php">Destinations <i class="fas fa-chevron-right"></i></a></li>
                    <li><a href="<?= BASE_URL ?>/tourist/events.php">Events <i class="fas fa-chevron-right"></i></a></li>
                    <li><a href="<?= BASE_URL ?>/tourist/destinations.php?cat=beaches">Beaches <i class="fas fa-chevron-right"></i></a></li>
                    <li><a href="<?= BASE_URL ?>/tourist/destinations.php?cat=nature_adventure">Nature <i class="fas fa-chevron-right"></i></a></li>
                    <li><a href="<?= BASE_URL ?>/tourist/destinations.php?cat=heritage_culture">Heritage <i class="fas fa-chevron-right"></i></a></li>
                </ul>
            </div>
            <div class="col-lg-2 col-md-6">
                <h6 class="footer-heading">Account</h6>
                <ul class="footer-links">
                    <li><a href="<?= BASE_URL ?>/auth/register.php">Register <i class="fas fa-chevron-right"></i></a></li>
                    <li><a href="<?= BASE_URL ?>/auth/login.php">Login <i class="fas fa-chevron-right"></i></a></li>
                    <li><a href="<?= BASE_URL ?>/tourist/bookings.php">My Bookings <i class="fas fa-chevron-right"></i></a></li>
                    <li><a href="<?= BASE_URL ?>/tourist/feedback.php">Leave Feedback <i class="fas fa-chevron-right"></i></a></li>
                </ul>
            </div>
            <div class="col-lg-4 col-md-6">
                <h6 class="footer-heading">Stay Updated</h6>
                <p style="font-size:0.85rem;color:rgba(255,255,255,0.45);margin-bottom:14px;">Get the latest news on new destinations, events, and exclusive tour deals.</p>
                <div class="footer-newsletter-input">
                    <input type="email" placeholder="Enter your email">
                    <button type="button"><i class="fas fa-paper-plane"></i></button>
                </div>
            </div>
        </div>
        <div class="footer-bottom">
            <p>&copy; <?= date('Y') ?> BINALGO — Binalbagan Tourism. All rights reserved.</p>
            <div class="footer-bottom-links">
                <a href="#">Privacy Policy</a>
                <a href="#">Terms of Service</a>
                <a href="#">Help Center</a>
            </div>
        </div>
    </div>
</footer>

<!-- Back to Top -->
<button class="footer-top-btn" id="backToTop" title="Back to top" aria-label="Back to top">
    <i class="fas fa-arrow-up"></i>
</button>

<!-- Toast -->
<div class="landing-toast" id="landingToast" role="status" aria-live="polite">
    <i class="fas fa-heart" aria-hidden="true"></i>
    <span class="landing-toast-text"></span>
</div>

<!-- Quick Preview Modal -->
<div class="modal fade dest-quick-modal" id="destQuickModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="qp-img-wrap">
                <img src="" alt="" class="qp-img" id="qpImg">
                <span class="qp-tag" id="qpTag"></span>
            </div>
            <div class="qp-body">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div>
                        <h4 class="qp-title" id="qpTitle"></h4>
                        <div class="qp-loc" id="qpLoc"></div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <p class="qp-desc" id="qpDesc"></p>
                <div class="qp-stat">
                    <div class="q"><div class="n" id="qpFee">Free</div><div class="l">Entrance fee</div></div>
                    <div class="q"><div class="n" id="qpRating">&mdash;</div><div class="l">Rating</div></div>
                    <div class="q"><div class="n" id="qpReviews">0</div><div class="l">Reviews</div></div>
                </div>
                <a id="qpViewLink" href="#" class="btn w-100 mt-1" style="border-radius:12px;font-weight:700;background:linear-gradient(135deg,#0c6e5e,#10b981);color:#fff;min-height:48px;">
                    View Full Details <i class="fas fa-arrow-right ms-2"></i>
                </a>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/script.js"></script>
<script src="<?= BASE_URL ?>/assets/js/landing.js"></script>
</body>
</html>