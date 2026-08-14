document.addEventListener('DOMContentLoaded', function() {
    // Sidebar toggle
    const sidebarToggle = document.querySelector('.sidebar-toggle');
    const sidebar = document.querySelector('.sidebar');
    const contentWrapper = document.querySelector('.content-wrapper');

    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
            contentWrapper.classList.toggle('expanded');
            localStorage.setItem('sidebar-collapsed', sidebar.classList.contains('collapsed'));
        });
    }

    // Restore sidebar state
    if (localStorage.getItem('sidebar-collapsed') === 'true') {
        if (sidebar) sidebar.classList.add('collapsed');
        if (contentWrapper) contentWrapper.classList.add('expanded');
    }

    // Mobile sidebar toggle
    const mobileToggle = document.querySelector('.mobile-sidebar-toggle');
    if (mobileToggle && sidebar) {
        mobileToggle.addEventListener('click', function() {
            sidebar.classList.toggle('show');
            let overlay = document.querySelector('.sidebar-overlay');
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.className = 'sidebar-overlay';
                document.body.appendChild(overlay);
                overlay.addEventListener('click', function() {
                    sidebar.classList.remove('show');
                    overlay.classList.remove('show');
                });
            }
            overlay.classList.toggle('show');
        });
    }

    // Auto-dismiss flash messages after 5 seconds
    const flashMessages = document.querySelectorAll('.alert-dismissible');
    flashMessages.forEach(function(alert) {
        setTimeout(function() {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(function() { alert.remove(); }, 500);
        }, 5000);
    });

    // Confirmation dialogs for delete/cancel actions
    document.querySelectorAll('[data-confirm]').forEach(function(element) {
        element.addEventListener('click', function(e) {
            if (!confirm(this.dataset.confirm || 'Are you sure?')) {
                e.preventDefault();
            }
        });
    });

    // Star rating interaction
    const starRatings = document.querySelectorAll('.star-rating-input');
    starRatings.forEach(function(container) {
        const stars = container.querySelectorAll('.star');
        const input = container.querySelector('input[type="hidden"]');

        stars.forEach(function(star, index) {
            star.addEventListener('mouseenter', function() {
                stars.forEach(function(s, i) {
                    s.classList.toggle('filled', i <= index);
                });
            });

            star.addEventListener('click', function() {
                if (input) input.value = index + 1;
                stars.forEach(function(s, i) {
                    s.classList.toggle('filled', i <= index);
                });
            });
        });

        container.addEventListener('mouseleave', function() {
            const val = input ? parseInt(input.value) || 0 : 0;
            stars.forEach(function(s, i) {
                s.classList.toggle('filled', i < val);
            });
        });
    });

    // Chat: send message on Enter key
    const chatInput = document.querySelector('#chat-input');
    const chatSendBtn = document.querySelector('#chat-send-btn');

    if (chatInput && chatSendBtn) {
        chatInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                chatSendBtn.click();
            }
        });
    }

    // Chat: auto-scroll to bottom
    const chatMessages = document.querySelector('.chat-messages');
    if (chatMessages) {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    // File upload preview
    const fileInputs = document.querySelectorAll('.file-upload input[type="file"]');
    fileInputs.forEach(function(input) {
        input.addEventListener('change', function() {
            const preview = this.closest('.file-upload').querySelector('.file-preview');
            if (preview && this.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML = '<img src="' + e.target.result + '" class="img-thumbnail" style="max-height:150px">';
                };
                reader.readAsDataURL(this.files[0]);
            }
        });
    });

    // Notification polling (every 60 seconds)
    setInterval(function() {
        var badge = document.querySelector('.notification-badge');
        if (!badge) return;
        var baseUrl = document.querySelector('meta[name="base-url"]');
        var base = baseUrl ? baseUrl.getAttribute('content') : '/Tourism';
        fetch(base + '/api/notifications.php?action=count')
            .then(function(response) { return response.text(); })
            .then(function(data) {
                var count = parseInt(data);
                if (!isNaN(count)) {
                    badge.textContent = count;
                    badge.style.display = count > 0 ? 'flex' : 'none';
                }
            })
            .catch(function() {});
    }, 60000);

    // Initialize Bootstrap tooltips
    var tooltipTriggers = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    tooltipTriggers.forEach(function(el) {
        if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
            new bootstrap.Tooltip(el);
        }
    });

    // Back to top button
    var backToTop = document.querySelector('#back-to-top');
    if (backToTop) {
        window.addEventListener('scroll', function() {
            backToTop.style.display = window.scrollY > 300 ? 'flex' : 'none';
        });
        backToTop.addEventListener('click', function() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    // Select all checkbox
    var selectAll = document.querySelector('#select-all');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            var checkboxes = document.querySelectorAll('.row-checkbox');
            checkboxes.forEach(function(cb) {
                cb.checked = selectAll.checked;
            });
        });
    }

    // Search filter - live search on tables
    var tableSearch = document.querySelector('#table-search');
    if (tableSearch) {
        tableSearch.addEventListener('input', function() {
            var query = this.value.toLowerCase();
            var table = document.querySelector(this.dataset.table || 'table tbody');
            if (table) {
                var rows = table.querySelectorAll('tr');
                rows.forEach(function(row) {
                    row.style.display = row.textContent.toLowerCase().indexOf(query) > -1 ? '' : 'none';
                });
            }
        });
    }

    // Print page function
    window.printPage = function() {
        window.print();
    };

    // Dropdown toggle
    document.querySelectorAll('[data-dropdown]').forEach(function(trigger) {
        trigger.addEventListener('click', function(e) {
            e.stopPropagation();
            var menu = document.querySelector(this.dataset.dropdown);
            if (menu) {
                document.querySelectorAll('.dropdown-menu.show').forEach(function(m) {
                    if (m !== menu) m.classList.remove('show');
                });
                menu.classList.toggle('show');
            }
        });
    });

    document.addEventListener('click', function() {
        document.querySelectorAll('.dropdown-menu.show').forEach(function(m) {
            m.classList.remove('show');
        });
    });

    // Form validation helper
    var forms = document.querySelectorAll('.needs-validation');
    forms.forEach(function(form) {
        form.addEventListener('submit', function(e) {
            if (!form.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
            }
            form.classList.add('was-validated');
        });
    });
});

// AJAX helper
function ajaxRequest(url, method, data, callback) {
    var xhr = new XMLHttpRequest();
    xhr.open(method, url, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            if (xhr.status === 200) {
                try {
                    callback(null, JSON.parse(xhr.responseText));
                } catch (err) {
                    callback(null, xhr.responseText);
                }
            } else {
                callback(new Error('Request failed'));
            }
        }
    };
    xhr.send(data);
}
