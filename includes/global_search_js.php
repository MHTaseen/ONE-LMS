<script>
(function() {
    // Universal Search Logic — shared across all pages
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput    = document.getElementById('universalSearchInput');
        const searchDropdown = document.getElementById('universalSearchDropdown');
        const filterToggle   = document.getElementById('searchFilterToggle');
        const filterPanel    = document.getElementById('searchFilterPanel');
        const filterChips    = document.querySelectorAll('.search-chip');

        if (!searchInput || !searchDropdown) return;

        let searchTimeout       = null;
        let selectedFilter      = 'features';

        const filterLabels = {
            messages:  'Messages',
            materials: 'Course Materials',
            routine:   'Routine',
            features:  'App Features'
        };

        const getSearchIcon = (type) => {
            if (type === 'Feature')         return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>';
            if (type === 'Message')         return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
            if (type === 'Course Material') return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
            if (type === 'Routine')         return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>';
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><circle cx="12" cy="12" r="10"/></svg>';
        };

        const renderResults = (data) => {
            if (!data || data.length === 0) {
                searchDropdown.innerHTML = '<div class="search-loading">No results found.</div>';
                return;
            }
            searchDropdown.innerHTML = '';
            data.forEach(item => {
                const a = document.createElement('a');
                a.href = item.url;
                a.className = 'search-result-item';
                a.innerHTML = `
                    <div class="search-result-icon">${getSearchIcon(item.type)}</div>
                    <div class="search-result-text">
                        <span class="search-result-title">${item.title}</span>
                        <span class="search-result-type">${item.type}</span>
                        ${item.meta ? `<span class="search-result-meta">${item.meta}</span>` : ''}
                    </div>
                `;
                searchDropdown.appendChild(a);
            });
        };

        const runSearch = () => {
            const query = searchInput.value.trim();
            clearTimeout(searchTimeout);
            if (query.length === 0) {
                searchDropdown.classList.remove('active');
                searchDropdown.innerHTML = '';
                return;
            }
            searchDropdown.classList.add('active');
            searchDropdown.innerHTML = '<div class="search-loading">Searching...</div>';
            searchTimeout = setTimeout(() => {
                // Resolve path to universal_search.php regardless of current page depth
                const base = document.querySelector('base')?.href || window.location.href.replace(/\/[^/]*$/, '/');
                fetch(`${base}universal_search.php?q=${encodeURIComponent(query)}&filter=${encodeURIComponent(selectedFilter)}`)
                    .then(r => r.json())
                    .then(renderResults)
                    .catch(() => { searchDropdown.innerHTML = '<div class="search-loading">Error fetching results.</div>'; });
            }, 200);
        };

        searchInput.addEventListener('input', runSearch);

        searchInput.addEventListener('focus', function() {
            if (this.value.trim().length > 0 && searchDropdown.innerHTML !== '') {
                searchDropdown.classList.add('active');
            }
        });

        if (filterToggle && filterPanel) {
            filterToggle.addEventListener('click', function(e) {
                e.stopPropagation();
                const isOpen = filterPanel.classList.toggle('active');
                filterToggle.classList.toggle('active', isOpen);
                filterToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });
        }

        filterChips.forEach(chip => {
            chip.addEventListener('click', function(e) {
                e.stopPropagation();
                selectedFilter = this.dataset.filter;
                filterToggle?.setAttribute('aria-label', `Search type: ${filterLabels[selectedFilter] || 'App Features'}`);
                filterChips.forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                filterPanel?.classList.remove('active');
                filterToggle?.classList.remove('active');
                filterToggle?.setAttribute('aria-expanded', 'false');
                if (searchInput.value.trim().length > 0) runSearch();
            });
        });

        document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) &&
                !searchDropdown.contains(e.target) &&
                !filterPanel?.contains(e.target) &&
                !filterToggle?.contains(e.target)) {
                searchDropdown.classList.remove('active');
                filterPanel?.classList.remove('active');
                filterToggle?.classList.remove('active');
                filterToggle?.setAttribute('aria-expanded', 'false');
            }
        });
    });
})();
</script>

<script>
// Global notification deletion functions
window.deleteNotif = function(e, id) {
    e.preventDefault();
    e.stopPropagation();
    fetch('delete_notification.php?id=' + id)
    .then(res => res.text())
    .then(() => {
        const el = document.getElementById('notif_' + id);
        if(el) el.remove();
    });
};

window.deleteAllNotifs = function(e) {
    e.preventDefault();
    e.stopPropagation();
    fetch('delete_all_notifications.php')
    .then(res => res.text())
    .then(() => {
        const notifList = document.getElementById('notifList');
        if (notifList) {
            notifList.innerHTML = '<div style="padding:16px; text-align:center; color:var(--text-secondary); font-size:0.9rem;">No notifications.</div>';
        }
    });
};

// Initialize Notification Dropdown
document.addEventListener('DOMContentLoaded', function() {
    const notifBtn = document.getElementById('notifBtn');
    const notifDropdown = document.getElementById('notifDropdown');
    if(notifBtn && notifDropdown) {
        notifBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            notifDropdown.classList.toggle('show');
            const badge = notifBtn.querySelector('.notif-badge');
            if(badge && notifDropdown.classList.contains('show')) {
                fetch('mark_notifications_read.php', {method: 'POST'})
                .then(res => res.text())
                .then(() => { badge.style.display = 'none'; });
            }
        });
        document.addEventListener('click', (e) => {
            if(!notifBtn.contains(e.target) && !notifDropdown.contains(e.target)) {
                notifDropdown.classList.remove('show');
            }
        });
    }
});
</script>
