/* ============================================================
   BINALGO Landing Page — Interactions
   Depends on window.Auth? No. Standalone.
   ============================================================ */
(function () {
  "use strict";

  var BASE = (document.querySelector('meta[name="base-url"]') || {}).content || '';
  var WISHLIST_KEY = 'binalgo_wishlist';

  /* ---------- helpers ---------- */
  function $(sel, ctx) {
    return (ctx || document).querySelector(sel);
  }
  function $$(sel, ctx) {
    return Array.prototype.slice.call((ctx || document).querySelectorAll(sel));
  }
  function getWishlist() {
    try {
      return JSON.parse(localStorage.getItem(WISHLIST_KEY) || '[]');
    } catch (e) {
      return [];
    }
  }
  function setWishlist(list) {
    localStorage.setItem(WISHLIST_KEY, JSON.stringify(list));
  }
  var toastTimer = null;
  function showToast(msg) {
    var t = $('#landingToast');
    if (!t) return;
    t.classList.add('show');
    t.querySelector('.landing-toast-text').textContent = msg;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () {
      t.classList.remove('show');
    }, 2200);
  }

  /* ---------- topbar + back to top ---------- */
  var topbar = $('#landingTopbar');
  var backBtn = $('#backToTop');
  function onScroll() {
    var y = window.scrollY || window.pageYOffset;
    if (topbar) topbar.classList.toggle('scrolled', y > 20);
    if (backBtn) backBtn.classList.toggle('visible', y > 420);
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
  if (backBtn) {
    backBtn.addEventListener('click', function () {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  }

  /* ---------- reveal on scroll ---------- */
  var revealEls = $$('.reveal');
  if ('IntersectionObserver' in window && revealEls.length) {
    var revealObs = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) {
          en.target.classList.add('in');
          revealObs.unobserve(en.target);
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
    revealEls.forEach(function (el) { revealObs.observe(el); });
  } else {
    revealEls.forEach(function (el) { el.classList.add('in'); });
  }

  /* ---------- smooth scroll CTA ---------- */
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[data-scroll]');
    if (!a) return;
    var hash = a.getAttribute('href');
    if (!hash || hash.charAt(0) !== '#') return;
    var target = document.querySelector(hash);
    if (!target) return;
    e.preventDefault();
    var top = target.getBoundingClientRect().top + (window.scrollY || window.pageYOffset) - 72;
    window.scrollTo({ top: top, behavior: 'smooth' });
    try { history.replaceState(null, '', hash); } catch (err) {}
  });

  /* ---------- count-up stats ---------- */
  var counters = $$('.stat-num[data-counter]');
  function animateCounter(el) {
    var target = parseFloat(el.getAttribute('data-counter'));
    var decimals = parseInt(el.getAttribute('data-decimals') || '0', 10);
    var suffix = el.getAttribute('data-suffix') || '';
    var dur = 1300;
    var start = null;
    function frame(ts) {
      if (!start) start = ts;
      var p = Math.min((ts - start) / dur, 1);
      var eased = 1 - Math.pow(1 - p, 3);
      var val = target * eased;
      el.textContent = val.toFixed(decimals) + suffix;
      if (p < 1) requestAnimationFrame(frame);
    }
    requestAnimationFrame(frame);
  }
  if (counters.length) {
    if ('IntersectionObserver' in window) {
      var countObs = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (en.isIntersecting) {
            animateCounter(en.target);
            countObs.unobserve(en.target);
          }
        });
      }, { threshold: 0.4 });
      counters.forEach(function (el) { countObs.observe(el); });
    } else {
      counters.forEach(animateCounter);
    }
  }

  /* ---------- destination image skeleton ---------- */
  function markImgLoaded(img) {
    if (img.classList.contains('is-loaded')) return;
    img.classList.add('is-loaded');
    var wrap = img.closest('.dest-img-wrap');
    if (wrap) wrap.classList.add('has-loaded');
  }
  function initDests() {
    $$('.dest-img').forEach(function (img) {
      if (img.complete && img.naturalWidth) {
        markImgLoaded(img);
      } else {
        img.addEventListener('load', function () { markImgLoaded(img); });
        img.addEventListener('error', function () {
          img.classList.add('is-loaded');
          var wrap = img.closest('.dest-img-wrap');
          if (wrap) wrap.classList.add('has-loaded');
        });
      }
    });
  }
  document.addEventListener('load', initDests);
  initDests();

  /* ---------- bookmark toggle (local wishlist) ---------- */
  function syncBookmarks() {
    var list = getWishlist();
    $$('.dest-bookmark').forEach(function (b) {
      b.classList.toggle('active', list.indexOf(b.getAttribute('data-id')) !== -1);
      var icon = b.querySelector('i');
      icon.className = (list.indexOf(b.getAttribute('data-id')) !== -1 ? 'fas' : 'far') + ' fa-heart';
    });
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('.dest-bookmark');
    if (!b) return;
    e.preventDefault();
    var id = b.getAttribute('data-id');
    var list = getWishlist();
    var idx = list.indexOf(id);
    if (idx === -1) {
      list.push(id);
      showToast('Added to your wishlist');
    } else {
      list.splice(idx, 1);
      showToast('Removed from wishlist');
    }
    setWishlist(list);
    syncBookmarks();
  });
  syncBookmarks();

  /* ---------- quick preview modal ---------- */
  var qpModalEl = $('#destQuickModal');
  function openQuickPreview(card) {
    if (!qpModalEl) {
      window.location.href = card.getAttribute('data-detail');
      return;
    }
    $('#qpImg').src = card.getAttribute('data-img');
    $('#qpTag').textContent = card.getAttribute('data-diff');
    $('#qpTag').style.background = card.getAttribute('data-diff-color');
    $('#qpTitle').textContent = card.getAttribute('data-name');
    $('#qpLoc').textContent = card.getAttribute('data-loc');
    $('#qpDesc').textContent = card.getAttribute('data-desc') || 'No description available yet.';
    $('#qpFee').textContent = card.getAttribute('data-fee');
    $('#qpRating').textContent = card.getAttribute('data-rating');
    var reviews = card.getAttribute('data-reviews');
    $('#qpReviews').textContent = reviews === '1' ? '1 review' : (reviews || 0) + ' reviews';
    $('#qpViewLink').href = card.getAttribute('data-detail');
    bootstrap.Modal.getOrCreateInstance(qpModalEl).show();
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('.dest-quickview');
    if (!b) return;
    e.preventDefault();
    e.stopPropagation();
    var card = b.closest('.dest-card');
    if (card) openQuickPreview(card);
  });

  /* ---------- feature card pointer spotlight ---------- */
  $$('.feature-card').forEach(function (card) {
    card.addEventListener('mousemove', function (e) {
      var r = card.getBoundingClientRect();
      card.style.setProperty('--mx', ((e.clientX - r.left) / r.width) * 100 + '%');
      card.style.setProperty('--my', ((e.clientY - r.top) / r.height) * 100 + '%');
    });
  });

  /* ---------- hero floating particle canvas ---------- */
  var canvas = $('#heroCanvas');
  if (canvas) {
    var ctx = canvas.getContext('2d');
    var w, h, particles = [];
    var mouse = { x: -1000, y: -1000 };
    var PCOUNT = 50, CDIST = 130, MRAD = 150;
    function resize() {
      var hero = canvas.parentElement;
      w = canvas.width = hero.offsetWidth;
      h = canvas.height = hero.offsetHeight;
    }
    resize();
    window.addEventListener('resize', resize);
    canvas.parentElement.addEventListener('mousemove', function (e) {
      var r = canvas.getBoundingClientRect();
      mouse.x = e.clientX - r.left;
      mouse.y = e.clientY - r.top;
    });
    canvas.parentElement.addEventListener('mouseleave', function () {
      mouse.x = -1000;
      mouse.y = -1000;
    });
    function P() {
      this.x = Math.random() * w;
      this.y = Math.random() * h;
      this.vx = (Math.random() - 0.5) * 0.7;
      this.vy = (Math.random() - 0.5) * 0.7;
      this.r = Math.random() * 2.5 + 1;
      this.a = Math.random() * 0.35 + 0.15;
    }
    for (var i = 0; i < PCOUNT; i++) particles.push(new P());
    (function draw() {
      ctx.clearRect(0, 0, w, h);
      for (var i = 0; i < particles.length; i++) {
        var p = particles[i];
        p.x += p.vx;
        p.y += p.vy;
        if (p.x < 0 || p.x > w) p.vx *= -1;
        if (p.y < 0 || p.y > h) p.vy *= -1;
        var dx = mouse.x - p.x, dy = mouse.y - p.y, d = Math.sqrt(dx * dx + dy * dy);
        if (d < MRAD) {
          p.x -= dx * 0.015;
          p.y -= dy * 0.015;
        }
        ctx.beginPath();
        ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
        ctx.fillStyle = 'rgba(255,255,255,' + p.a + ')';
        ctx.fill();
        for (var j = i + 1; j < particles.length; j++) {
          var p2 = particles[j], dd = Math.hypot(p.x - p2.x, p.y - p2.y);
          if (dd < CDIST) {
            ctx.beginPath();
            ctx.moveTo(p.x, p.y);
            ctx.lineTo(p2.x, p2.y);
            ctx.strokeStyle = 'rgba(255,255,255,' + (0.1 * (1 - dd / CDIST)) + ')';
            ctx.lineWidth = 0.5;
            ctx.stroke();
          }
        }
        var dm = Math.hypot(p.x - mouse.x, p.y - mouse.y);
        if (dm < MRAD) {
          ctx.beginPath();
          ctx.moveTo(p.x, p.y);
          ctx.lineTo(mouse.x, mouse.y);
          ctx.strokeStyle = 'rgba(12,110,94,' + (0.35 * (1 - dm / MRAD)) + ')';
          ctx.lineWidth = 1;
          ctx.stroke();
        }
      }
      requestAnimationFrame(draw);
    })();
  }

  window.BINALGO_LANDING = {
    showToast: showToast,
    refreshBookmarks: syncBookmarks,
  };
})();