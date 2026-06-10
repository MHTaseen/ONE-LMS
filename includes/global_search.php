        <!-- Universal Search Bar -->
        <div class="universal-search-container">
            <div class="universal-search-field">
                <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                <input type="text" id="universalSearchInput" class="universal-search-input" placeholder="Search messages, materials, routine, features..." autocomplete="off">
                <button type="button" id="searchFilterToggle" class="search-filter-toggle" aria-label="Open search filters" aria-expanded="false">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="6 9 12 15 18 9"></polyline>
                    </svg>
                </button>
            </div>
            <div id="searchFilterPanel" class="search-filter-panel">
                <div class="search-filter-title">Search Type</div>
                <div class="search-chip-list">
                    <button type="button" class="search-chip" data-filter="messages">Messages</button>
                    <button type="button" class="search-chip" data-filter="materials">Course Materials</button>
                    <button type="button" class="search-chip" data-filter="routine">Routine</button>
                    <button type="button" class="search-chip active" data-filter="features">App Features</button>
                </div>
            </div>
            <div id="universalSearchDropdown" class="search-dropdown">
                <!-- Results populate here -->
            </div>
        </div>
