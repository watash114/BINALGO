<?php
require_once __DIR__ . '/../includes/layout.php';
require_role('tourist');

$db = Database::getInstance()->getConnection();
$user = current_user();

render_page('tourist', 'about.php', 'About Binalbagan', function () use ($user) {
?>
<style>
.about-page-wrap{margin:0 0 0 0;}

/* Hero Banner */
.about-hero-full{position:relative;min-height:380px;overflow:hidden;display:flex;align-items:flex-end;border-radius:20px;margin:0 0 28px;box-shadow:0 16px 48px rgba(0,0,0,0.12);}
.about-hero-full .hero-bg-img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;filter:brightness(0.45) saturate(1.2);transition:transform 8s ease;}
.about-hero-full:hover .hero-bg-img{transform:scale(1.03);}
.about-hero-full .hero-overlay{position:absolute;inset:0;background:linear-gradient(160deg,rgba(6,15,30,0.78) 0%,rgba(12,110,94,0.3) 50%,rgba(6,15,30,0.75) 100%);}
.about-hero-full .hero-content{position:relative;z-index:2;padding:48px 40px 40px;width:100%;}
.about-hero-full .hero-badge{display:inline-flex;align-items:center;gap:6px;background:rgba(52,211,153,0.15);border:1px solid rgba(52,211,153,0.3);backdrop-filter:blur(8px);border-radius:8px;padding:5px 14px;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;color:#34d399;margin-bottom:14px;}
.about-hero-full h1{font-size:2.4rem;font-weight:800;color:#fff;margin-bottom:8px;line-height:1.15;}
.about-hero-full h1 .accent{color:#34d399;}
.about-hero-full .hero-sub{font-size:1rem;color:rgba(255,255,255,0.75);max-width:560px;line-height:1.6;margin-bottom:20px;}
.about-hero-full .hero-tags{display:flex;flex-wrap:wrap;gap:8px;}
.about-hero-full .hero-tag{display:inline-flex;align-items:center;gap:5px;background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.15);backdrop-filter:blur(6px);border-radius:8px;padding:6px 14px;font-size:0.78rem;color:rgba(255,255,255,0.85);font-weight:500;}
.about-hero-full .hero-tag i{color:#34d399;font-size:0.72rem;}

/* Section Headers */
.about-section-label{font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:2px;color:var(--primary,#0c6e5e);margin-bottom:6px;}
.about-section-title{font-size:1.35rem;font-weight:800;color:var(--text-primary,#1e293b);margin-bottom:4px;}
.about-section-sub{font-size:0.88rem;color:var(--text-secondary,#64748b);margin-bottom:24px;}

/* Welcome Text Card */
.about-welcome-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:16px;padding:28px 32px;position:relative;overflow:hidden;}
.about-welcome-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,#0c6e5e,#10b981,#34d399);}
.about-welcome-card p{color:var(--text-secondary,#64748b);line-height:1.8;font-size:0.92rem;}
.about-welcome-card .lead-text{font-size:1.05rem;color:var(--text-primary,#1e293b);font-weight:500;}

/* Attraction Cards */
.attraction-media-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:16px;overflow:hidden;transition:all 0.35s cubic-bezier(.4,0,.2,1);position:relative;}
.attraction-media-card:hover{transform:translateY(-4px);box-shadow:0 16px 40px rgba(0,0,0,0.1);border-color:rgba(12,110,94,0.15);}
.attraction-media-card .icon-top-row{display:flex;align-items:center;justify-content:space-between;padding:20px 20px 0;}
.attraction-media-card .attraction-icon{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;}
.attraction-media-card .cat-badge{display:inline-flex;align-items:center;gap:5px;border-radius:8px;padding:5px 10px;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;color:#fff;}
.attraction-media-card .card-body-area{padding:16px 20px 20px;}
.attraction-media-card .card-body-area h6{font-weight:700;font-size:0.95rem;color:var(--text-primary,#1e293b);margin-bottom:6px;line-height:1.3;}
.attraction-media-card .card-body-area p{font-size:0.82rem;color:var(--text-secondary,#64748b);line-height:1.6;margin:0 0 12px;}
.attraction-media-card .card-body-area .card-footer{display:flex;align-items:center;justify-content:space-between;}
.attraction-media-card .card-body-area .view-link{font-size:0.78rem;font-weight:600;color:var(--primary,#0c6e5e);text-decoration:none;display:inline-flex;align-items:center;gap:4px;transition:gap 0.2s;}
.attraction-media-card .card-body-area .view-link:hover{gap:8px;}

/* Quick Facts */
.quick-facts-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:16px;overflow:hidden;}
.quick-facts-card .facts-header{padding:20px 24px 16px;border-bottom:1px solid var(--border-color,#e2e8f0);}
.quick-facts-card .facts-list{padding:8px 0;}
.fact-row{display:flex;align-items:center;gap:14px;padding:12px 24px;transition:background 0.2s;}
.fact-row:hover{background:rgba(12,110,94,0.03);}
.fact-row .fact-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:0.8rem;}
.fact-row .fact-label{font-size:0.75rem;color:var(--text-muted,#94a3b8);font-weight:500;text-transform:uppercase;letter-spacing:0.5px;}
.fact-row .fact-value{font-size:0.88rem;font-weight:600;color:var(--text-primary,#1e293b);}

/* Festival Card */
.festival-hero-card{background:linear-gradient(135deg,#0c6e5e 0%,#0a5c4f 60%,#0e7490 100%);border-radius:16px;padding:28px 24px;color:#fff;text-align:center;position:relative;overflow:hidden;}
.festival-hero-card::before{content:'';position:absolute;top:-30px;right:-30px;width:120px;height:120px;border-radius:50%;background:rgba(255,255,255,0.06);}
.festival-hero-card::after{content:'';position:absolute;bottom:-20px;left:20px;width:80px;height:80px;border-radius:50%;background:rgba(255,255,255,0.04);}
.festival-hero-card .fest-icon{width:64px;height:64px;border-radius:50%;background:rgba(255,255,255,0.12);border:2px solid rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:1.6rem;}
.festival-hero-card h6{font-weight:700;font-size:1rem;margin-bottom:6px;position:relative;z-index:1;}
.festival-hero-card p{font-size:0.82rem;opacity:0.8;margin:0;position:relative;z-index:1;}
.festival-hero-card .fest-date{display:inline-flex;align-items:center;gap:6px;margin-top:14px;padding:6px 14px;background:rgba(255,255,255,0.15);border-radius:8px;font-size:0.78rem;font-weight:600;position:relative;z-index:1;}

/* Culture Card */
.culture-card{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:16px;padding:24px;position:relative;overflow:hidden;}
.culture-card::before{content:'';position:absolute;bottom:0;right:0;width:100px;height:100px;background:rgba(12,110,94,0.04);border-radius:50%;transform:translate(30%,30%);}
.culture-card h6{font-weight:700;color:var(--text-primary,#1e293b);margin-bottom:10px;}
.culture-card p{font-size:0.88rem;color:var(--text-secondary,#64748b);line-height:1.75;margin:0;}
.culture-tags{display:flex;flex-wrap:wrap;gap:6px;margin-top:14px;}
.culture-tag{display:inline-flex;align-items:center;gap:4px;padding:5px 12px;border-radius:8px;font-size:0.72rem;font-weight:600;}

/* Emergency */
.emergency-section-header{display:flex;align-items:center;gap:12px;margin-bottom:20px;}
.emergency-section-icon{width:44px;height:44px;border-radius:12px;background:rgba(239,68,68,0.1);display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:#ef4444;}
.emergency-card-v2{background:var(--card-bg,#fff);border:1px solid var(--border-color,#e2e8f0);border-radius:14px;padding:18px 20px;transition:all 0.25s;height:100%;display:flex;align-items:flex-start;gap:14px;}
.emergency-card-v2:hover{box-shadow:0 6px 20px rgba(0,0,0,0.06);transform:translateY(-3px);border-color:rgba(12,110,94,0.12);}
.emergency-card-v2 .em-icon{width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.emergency-card-v2 .em-name{font-weight:700;font-size:0.9rem;color:var(--text-primary,#1e293b);margin-bottom:2px;}
.emergency-card-v2 .em-office{font-size:0.78rem;color:var(--text-muted,#94a3b8);margin-bottom:6px;}
.emergency-card-v2 .em-phone{font-weight:700;font-size:0.95rem;color:var(--primary,#0c6e5e);text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:color 0.2s;}
.emergency-card-v2 .em-phone:hover{color:#0a5c4f;}
.emergency-card-v2 .em-note{font-size:0.72rem;color:var(--text-muted,#94a3b8);margin-top:4px;}

/* Disclaimer */
.about-disclaimer{background:linear-gradient(135deg,rgba(245,158,11,0.08),rgba(251,191,36,0.08));border:1px solid rgba(245,158,11,0.2);border-radius:14px;padding:18px 22px;display:flex;align-items:flex-start;gap:14px;}
.about-disclaimer .disc-icon{width:40px;height:40px;border-radius:10px;background:rgba(245,158,11,0.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;color:#f59e0b;font-size:1rem;}
.about-disclaimer .disc-title{font-weight:700;font-size:0.88rem;color:#92400e;margin-bottom:4px;}
.about-disclaimer .disc-text{font-size:0.82rem;color:#a16207;line-height:1.6;margin:0;}
</style>

<div class="about-page-wrap">

<!-- Hero Banner -->
<div class="about-hero-full">
    <img src="<?= BASE_URL ?>/assets/images/download.jpg" alt="Binalbagan Landscape" class="hero-bg-img">
    <div class="hero-overlay"></div>
    <div class="hero-content">
        <div class="hero-badge"><i class="fas fa-landmark"></i> Est. 1572</div>
        <h1>Binalbagan, <span class="accent">Negros Occidental</span></h1>
        <p class="hero-sub">The oldest town on Negros Island — where history, nature, and warm Hiligaynon hospitality come together.</p>
        <div class="hero-tags">
            <span class="hero-tag"><i class="fas fa-map-marker-alt"></i> Western Visayas</span>
            <span class="hero-tag"><i class="fas fa-users"></i> 72,594 Residents</span>
            <span class="hero-tag"><i class="fas fa-calendar-alt"></i> Founded 1572</span>
            <span class="hero-tag"><i class="fas fa-language"></i> Hiligaynon</span>
        </div>
    </div>
</div>

<!-- Welcome + Attractions + Sidebar -->
<div class="row g-4 mt-1">
    <!-- Main Content -->
    <div class="col-lg-8">
        <!-- Welcome -->
        <div class="about-welcome-card mb-4">
            <p class="lead-text mb-3">Welcome to Binalbagan — the <strong>"Banwang Panganay"</strong> (Oldest Town) of Negros Island.</p>
            <p>Binalbagan is a municipality in the province of Negros Occidental, Philippines. With a population of over 72,000, it is recognized as the oldest town on Negros Island. Founded in 1572, it is one of the earliest settlements in the region, rich in history and culture. The town is nestled between lush mountains and the coastline, offering a unique blend of natural beauty, warm hospitality, and vibrant local traditions.</p>
            <p>Whether you're here for adventure, relaxation, or cultural immersion, Binalbagan has something for everyone.</p>
        </div>

        <!-- Attractions -->
        <div class="mb-4">
            <div class="about-section-label"><i class="fas fa-compass me-1"></i> Discover</div>
            <h5 class="about-section-title">Notable Attractions</h5>
            <p class="about-section-sub">Explore the natural wonders and historic landmarks that make Binalbagan unique.</p>
            <div class="row g-3">
                <!-- Binalian Falls -->
                <div class="col-sm-6">
                    <div class="attraction-media-card">
                        <div class="icon-top-row">
                            <div class="attraction-icon" style="background:rgba(59,130,246,0.12);"><i class="fas fa-water" style="color:#3b82f6;"></i></div>
                            <span class="cat-badge" style="background:rgba(59,130,246,0.85);"><i class="fas fa-water"></i> Nature</span>
                        </div>
                        <div class="card-body-area">
                            <h6>Binadlan Falls (Bambi Falls)</h6>
                            <p>A breathtaking 100-foot waterfall in Barangay Biao, surrounded by pristine tropical forest.</p>
                            <div class="card-footer">
                                <span style="font-size:0.72rem;color:var(--text-muted,#94a3b8);"><i class="fas fa-map-pin me-1"></i>Brgy. Biao</span>
                                <a href="<?= BASE_URL ?>/tourist/destinations.php" class="view-link">Explore <i class="fas fa-arrow-right" style="font-size:0.65rem;"></i></a>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Mount Hermit -->
                <div class="col-sm-6">
                    <div class="attraction-media-card">
                        <div class="icon-top-row">
                            <div class="attraction-icon" style="background:rgba(16,185,129,0.12);"><i class="fas fa-mountain" style="color:#10b981;"></i></div>
                            <span class="cat-badge" style="background:rgba(16,185,129,0.85);"><i class="fas fa-mountain"></i> Adventure</span>
                        </div>
                        <div class="card-body-area">
                            <h6>Mount Hermit</h6>
                            <p>An emerging upland tourist destination in Barangay Bi-ao, ideal for trekking and nature walks.</p>
                            <div class="card-footer">
                                <span style="font-size:0.72rem;color:var(--text-muted,#94a3b8);"><i class="fas fa-map-pin me-1"></i>Brgy. Bi-ao</span>
                                <a href="<?= BASE_URL ?>/tourist/destinations.php" class="view-link">Explore <i class="fas fa-arrow-right" style="font-size:0.65rem;"></i></a>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- San Isidro -->
                <div class="col-sm-6">
                    <div class="attraction-media-card">
                        <div class="icon-top-row">
                            <div class="attraction-icon" style="background:rgba(124,58,237,0.12);"><i class="fas fa-church" style="color:#7c3aed;"></i></div>
                            <span class="cat-badge" style="background:rgba(124,58,237,0.85);"><i class="fas fa-church"></i> Heritage</span>
                        </div>
                        <div class="card-body-area">
                            <h6>San Isidro Labrador Parish</h6>
                            <p>The roots of Christianity on Negros Island began in this centuries-old historic church.</p>
                            <div class="card-footer">
                                <span style="font-size:0.72rem;color:var(--text-muted,#94a3b8);"><i class="fas fa-map-pin me-1"></i>Poblacion</span>
                                <a href="<?= BASE_URL ?>/tourist/destinations.php" class="view-link">Explore <i class="fas fa-arrow-right" style="font-size:0.65rem;"></i></a>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Coastal Beaches -->
                <div class="col-sm-6">
                    <div class="attraction-media-card">
                        <div class="icon-top-row">
                            <div class="attraction-icon" style="background:rgba(245,158,11,0.12);"><i class="fas fa-umbrella-beach" style="color:#f59e0b;"></i></div>
                            <span class="cat-badge" style="background:rgba(245,158,11,0.85);"><i class="fas fa-umbrella-beach"></i> Beach</span>
                        </div>
                        <div class="card-body-area">
                            <h6>Coastal Beaches</h6>
                            <p>Scenic shorelines along the southern coast, perfect for sunset watching and relaxation.</p>
                            <div class="card-footer">
                                <span style="font-size:0.72rem;color:var(--text-muted,#94a3b8);"><i class="fas fa-map-pin me-1"></i>South Coast</span>
                                <a href="<?= BASE_URL ?>/tourist/destinations.php" class="view-link">Explore <i class="fas fa-arrow-right" style="font-size:0.65rem;"></i></a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Culture & Community -->
        <div class="culture-card mb-4">
            <h6><i class="fas fa-heart me-2" style="color:#ef4444;"></i>Culture & Community</h6>
            <p>Binalbagan celebrates its annual <strong>Balbagan Festival</strong> every May, showcasing the town's rich heritage through street dancing, parades, and cultural performances. The community is known for its warm Hiligaynon hospitality, and the local economy thrives on agriculture (sugar production), manufacturing, and growing tourism.</p>
            <div class="culture-tags">
                <span class="culture-tag" style="background:rgba(239,68,68,0.1);color:#ef4444;"><i class="fas fa-calendar-star"></i> Balbagan Festival</span>
                <span class="culture-tag" style="background:rgba(59,130,246,0.1);color:#3b82f6;"><i class="fas fa-music"></i> Street Dancing</span>
                <span class="culture-tag" style="background:rgba(16,185,129,0.1);color:#10b981;"><i class="fas fa-hand-holding-heart"></i> Hospitality</span>
                <span class="culture-tag" style="background:rgba(245,158,11,0.1);color:#f59e0b;"><i class="fas fa-seedling"></i> Agriculture</span>
            </div>
        </div>

        <!-- Emergency Contacts -->
        <div class="emergency-section-header">
            <div class="emergency-section-icon"><i class="fas fa-phone-alt"></i></div>
            <div>
                <h5 class="about-section-title mb-0">Emergency Contacts</h5>
                <p class="about-section-sub mb-0">Important numbers to keep you safe during your visit.</p>
            </div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div class="emergency-card-v2">
                    <div class="em-icon" style="background:rgba(59,130,246,0.12);"><i class="fas fa-shield-alt" style="color:#3b82f6;"></i></div>
                    <div>
                        <div class="em-name">Police</div>
                        <div class="em-office">Binalbagan Municipal Police Station</div>
                        <a href="tel:+63347429024" class="em-phone"><i class="fas fa-phone" style="font-size:0.7rem;"></i> (034) 742-9024</a>
                        <div class="em-note">Emergency: <strong>911</strong> or <strong>117</strong></div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="emergency-card-v2">
                    <div class="em-icon" style="background:rgba(239,68,68,0.12);"><i class="fas fa-fire-extinguisher" style="color:#ef4444;"></i></div>
                    <div>
                        <div class="em-name">Fire Department</div>
                        <div class="em-office">Binalbagan BFP Station</div>
                        <a href="tel:+63347429019" class="em-phone"><i class="fas fa-phone" style="font-size:0.7rem;"></i> (034) 742-9019</a>
                        <div class="em-note">Emergency: <strong>911</strong> or <strong>116</strong></div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="emergency-card-v2">
                    <div class="em-icon" style="background:rgba(16,185,129,0.12);"><i class="fas fa-ambulance" style="color:#10b981;"></i></div>
                    <div>
                        <div class="em-name">Ambulance / Medical</div>
                        <div class="em-office">Binalbagan RHU (Rural Health Unit)</div>
                        <a href="tel:+63347428888" class="em-phone"><i class="fas fa-phone" style="font-size:0.7rem;"></i> (034) 742-8888</a>
                        <div class="em-note">Emergency: <strong>911</strong></div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="emergency-card-v2">
                    <div class="em-icon" style="background:rgba(245,158,11,0.12);"><i class="fas fa-hospital" style="color:#f59e0b;"></i></div>
                    <div>
                        <div class="em-name">Local Hospital</div>
                        <div class="em-office">Binalbagan Community Hospital</div>
                        <a href="tel:+63347428800" class="em-phone"><i class="fas fa-phone" style="font-size:0.7rem;"></i> (034) 742-8800</a>
                        <div class="em-note">24/7 Emergency Room</div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="emergency-card-v2">
                    <div class="em-icon" style="background:rgba(59,130,246,0.12);"><i class="fas fa-building" style="color:#3b82f6;"></i></div>
                    <div>
                        <div class="em-name">Municipal Hall</div>
                        <div class="em-office">Office of the Mayor, Binalbagan</div>
                        <a href="tel:+63343888024" class="em-phone"><i class="fas fa-phone" style="font-size:0.7rem;"></i> (034) 388-8024</a>
                        <div class="em-note">Mon–Fri 8AM–5PM</div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="emergency-card-v2">
                    <div class="em-icon" style="background:rgba(100,116,139,0.12);"><i class="fas fa-bolt" style="color:#64748b;"></i></div>
                    <div>
                        <div class="em-name">Disaster Response (MDRRMO)</div>
                        <div class="em-office">Municipal Disaster Risk Reduction</div>
                        <a href="tel:+63347429000" class="em-phone"><i class="fas fa-phone" style="font-size:0.7rem;"></i> (034) 742-9000</a>
                        <div class="em-note">24/7 Hotline</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Disclaimer -->
        <div class="about-disclaimer mb-4">
            <div class="disc-icon"><i class="fas fa-exclamation-triangle"></i></div>
            <div>
                <div class="disc-title">Important Reminder</div>
                <p class="disc-text">Please verify all emergency contact numbers with local authorities upon arrival. Phone numbers may change without prior notice. For immediate emergencies, dial <strong>911</strong> (Philippines nationwide emergency hotline).</p>
            </div>
        </div>
    </div>

    <!-- Right Sidebar -->
    <div class="col-lg-4">
        <!-- Quick Facts -->
        <div class="quick-facts-card mb-4">
            <div class="facts-header">
                <div class="about-section-label"><i class="fas fa-chart-bar me-1"></i> Reference</div>
                <h6 class="about-section-title mb-0">Quick Facts</h6>
            </div>
            <div class="facts-list">
                <div class="fact-row">
                    <div class="fact-icon" style="background:rgba(59,130,246,0.1);color:#3b82f6;"><i class="fas fa-map-marker-alt"></i></div>
                    <div><div class="fact-label">Province</div><div class="fact-value">Negros Occidental</div></div>
                </div>
                <div class="fact-row">
                    <div class="fact-icon" style="background:rgba(16,185,129,0.1);color:#10b981;"><i class="fas fa-globe-asia"></i></div>
                    <div><div class="fact-label">Region</div><div class="fact-value">Region VI (Western Visayas)</div></div>
                </div>
                <div class="fact-row">
                    <div class="fact-icon" style="background:rgba(245,158,11,0.1);color:#f59e0b;"><i class="fas fa-users"></i></div>
                    <div><div class="fact-label">Population</div><div class="fact-value">72,594 (2024)</div></div>
                </div>
                <div class="fact-row">
                    <div class="fact-icon" style="background:rgba(124,58,237,0.1);color:#7c3aed;"><i class="fas fa-calendar-alt"></i></div>
                    <div><div class="fact-label">Founded</div><div class="fact-value">1572</div></div>
                </div>
                <div class="fact-row">
                    <div class="fact-icon" style="background:rgba(239,68,68,0.1);color:#ef4444;"><i class="fas fa-mail-bulk"></i></div>
                    <div><div class="fact-label">ZIP Code</div><div class="fact-value">6107</div></div>
                </div>
                <div class="fact-row">
                    <div class="fact-icon" style="background:rgba(6,182,212,0.1);color:#06b6d4;"><i class="fas fa-phone"></i></div>
                    <div><div class="fact-label">Area Code</div><div class="fact-value">+63 (0)34</div></div>
                </div>
                <div class="fact-row">
                    <div class="fact-icon" style="background:rgba(236,72,153,0.1);color:#ec4899;"><i class="fas fa-language"></i></div>
                    <div><div class="fact-label">Language</div><div class="fact-value">Hiligaynon, Tagalog</div></div>
                </div>
                <div class="fact-row">
                    <div class="fact-icon" style="background:rgba(12,110,94,0.1);color:var(--primary,#0c6e5e);"><i class="fas fa-link"></i></div>
                    <div><div class="fact-label">Website</div><div class="fact-value"><a href="https://www.binalbagan.gov.ph" target="_blank" style="color:var(--primary,#0c6e5e);text-decoration:none;font-weight:600;">binalbagan.gov.ph <i class="fas fa-external-link-alt" style="font-size:0.6rem;"></i></a></div></div>
                </div>
            </div>
        </div>

        <!-- Balbagan Festival -->
        <div class="festival-hero-card">
            <div class="fest-icon"><i class="fas fa-masks-theater"></i></div>
            <h6>Balbagan Festival</h6>
            <p>Annual celebration of Binalbagan's rich heritage, culture, and community spirit.</p>
            <div class="fest-date"><i class="fas fa-calendar-alt"></i> Every May</div>
        </div>
    </div>
</div>

</div>

<?php }); ?>
