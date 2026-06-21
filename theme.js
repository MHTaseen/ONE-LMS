// theme.js - Client-side theme, translation, and typography controls

(function () {
    // Immediately apply theme and dyslexia font settings to prevent screen flash/jump
    const savedTheme = localStorage.getItem('theme');
    const systemPrefersLight = window.matchMedia('(prefers-color-scheme: light)').matches;
    
    if (savedTheme === 'light' || (!savedTheme && systemPrefersLight)) {
        document.documentElement.classList.add('light-theme');
    } else {
        document.documentElement.classList.remove('light-theme');
    }

    const savedDyslexia = localStorage.getItem('dyslexia');
    if (savedDyslexia === 'enabled') {
        document.documentElement.classList.add('dyslexia-font');
    }
})();

document.addEventListener('DOMContentLoaded', () => {
    // ── Theme Switcher ──
    const themeBtn = document.getElementById('themeToggleBtn');
    if (themeBtn) {
        themeBtn.addEventListener('click', () => {
            const isLight = document.documentElement.classList.toggle('light-theme');
            localStorage.setItem('theme', isLight ? 'light' : 'dark');
        });
    }

    // ── Translation Dictionary ──
    const dictionary = {
        // Top Navbar / Header
        "BRAC University Hub": "ব্র্যাক বিশ্ববিদ্যালয় হাব",
        "Back": "ফিরে যান",
        "Theme Toggle": "থিম পরিবর্তন",
        "Menu": "মেনু",
        "Logout": "লগআউট",

        // Drawer sections and items
        "Account": "অ্যাকাউন্ট",
        "My Profile": "আমার প্রোফাইল",
        "Academic": "একাডেমিক",
        "My Quiz": "আমার কুইজ",
        "Grade Sheet": "গ্রেড শীট",
        "Seat Capacity": "আসন ক্ষমতা",

        // New feature strings
        "Current Score": "বর্তমান স্কোর",
        "Attendance": "উপস্থিতি",
        "Submit Current Score": "বর্তমান স্কোর জমা দিন",
        "View your assignment and quiz grades for your enrolled courses.": "আপনার নিবন্ধিত কোর্সগুলির অ্যাসাইনমেন্ট এবং কুইজের গ্রেড দেখুন।",
        "Track your daily attendance record for your enrolled courses.": "আপনার নিবন্ধিত কোর্সগুলির প্রতিদিনের উপস্থিতির রেকর্ড ট্র্যাক করুন।",
        "Review and grade student assignments and quizzes.": "শিক্ষার্থীদের অ্যাসাইনমেন্ট এবং কুইজ পর্যালোচনা এবং গ্রেড করুন।",
        "Manage and track daily student attendance for your sections.": "আপনার সেকশনের জন্য প্রতিদিনের উপস্থিতির হিসাব রাখুন।",
        "Choose another course": "অন্য কোর্স নির্বাচন করুন",
        "Total Classes": "মোট ক্লাস",
        "Present": "উপস্থিত",
        "Late": "দেরি",
        "Absent": "অনুপস্থিত",
        "Save Attendance": "উপস্থিতি সংরক্ষণ করুন",
        "Select Date:": "তারিখ নির্বাচন করুন:",
        "No attendance records have been posted for this course yet.": "এই কোর্সের জন্য এখনও কোনো উপস্থিতির রেকর্ড পোস্ট করা হয়নি।",
        "Pending Grading": "মূল্যায়নের অপেক্ষায়",
        "Quiz Scores": "কুইজের স্কোর",
        "Assignment Scores": "অ্যাসাইনমেন্ট স্কোর",
        "Quiz Name": "কুইজের নাম",
        "Assignment Name": "অ্যাসাইনমেন্ট নাম",

        "Credits Completed": "সম্পন্ন ক্রেডিট",
        "Current CGPA": "বর্তমান সিজিপিএ",
        "Current Semester": "বর্তমান সেমিস্টার",
        "Joining Semester": "যোগদানের সেমিস্টার",

        "Course Materials": "কোর্স ম্যাটেরিয়ালস",
        "Add Course Materials": "কোর্স ম্যাটেরিয়ালস যোগ করুন",
        "Public Materials": "পাবলিক ম্যাটেরিয়ালস",
        "Active": "সক্রিয়",

        "Central Communication Media": "যোগাযোগ মাধ্যম",
        "Manage Notifications": "নোটিফিকেশন পরিচালনা",

        "Attendance Score": "উপস্থিতি স্কোর",
        "Mid": "মিড",
        "Final": "ফাইনাল",
        "Lab": "ল্যাব",
        "Mid Exam": "মিড পরীক্ষা",
        "Final Exam": "ফাইনাল পরীক্ষা",
        "Lab Score": "ল্যাব স্কোর",
        
        "Advising Dashboard": "অ্যাডভাইজিং ড্যাশবোর্ড",
        "My Assignments": "আমার অ্যাসাইনমেন্ট",
        "Faculty Panel": "ফ্যাকাল্টি প্যানেল",
        "Add Course": "কোর্স যোগ করুন",
        "Student messages": "শিক্ষার্থীদের বার্তা",
        "Publish Grades": "গ্রেড প্রকাশ করুন",
        "Routine": "রুটিন",
        "Advising": "অ্যাডভাইজিং",
        "Courses": "কোর্সসমূহ",
        "Course Status": "কোর্সের অবস্থা",
        "Deploy Assignments": "অ্যাসাইনমেন্ট প্রকাশ করুন",
        "Deploy Quiz": "কুইজ প্রকাশ করুন",
        "Accessibility": "অ্যাক্সেসিবিলিটি",
        "Preferences": "পছন্দসমূহ",
        "High Contrast Mode": "হাই কনট্রাস্ট মোড",
        "Reduce Motion": "মোশন কমান",
        "Keyboard Focus States": "কীবোর্ড ফোকাস স্টেটস",
        "Text to speech": "টেক্সট টু স্পিচ",
        "English/Bangla": "ইংরেজি/বাংলা",
        "Dyslexia-friendly Font": "ডিসলেক্সিয়া-বান্ধব ফন্ট",

        // Dashboard (landing.php) content
        "System Clearance Dashboard": "সিস্টেম ক্লিয়ারেন্স ড্যাশবোর্ড",
        "Clearance Authorization Node": "অনুমতি অনুমোদন নোড",
        "Student Level-I Clearance": "শিক্ষার্থী লেভেল-১ অনুমতি",
        "Faculty Level-II Clearance": "ফ্যাকাল্টি লেভেল-২ অনুমতি",
        "Guest Level-0 Restricted": "অতিথি লেভেল-০ সীমিত",
        "Institutional Email": "প্রাতিষ্ঠানিক ইমেল",
        "Identification ID": "শনাক্তকরণ আইডি",
        "Registrant ID": "নিবন্ধনকারী আইডি",
        "Full Name": "পূর্ণ নাম",
        "Role": "ভূমিকা",
        "Email": "ইমেল",
        "Verification": "যাচাইকরণ",
        "Department": "বিভাগ",
        "Close": "বন্ধ করুন",
        "Student": "শিক্ষার্থী",
        "Teacher": "শিক্ষক",
        "Guest": "অতিথি",
        "Verified Domain": "যাচাইকৃত ডোমেইন",
        "Unverified Guest": "অযাচাইকৃত অতিথি",

        // Profile modal
        "My Academic Profile": "আমার অ্যাকাডেমিক প্রোফাইল",
        "Clearance Level": "অনুমতির স্তর",

        // General terms / Buttons
        "Submit": "জমা দিন",
        "Cancel": "বাতিল করুন",
        "Edit": "সম্পাদনা করুন",
        "Delete": "মুছে ফেলুন",
        "Add": "যোগ করুন",
        "Save": "সংরক্ষণ করুন",
        "Select": "নির্বাচন করুন",
        "Status": "অবস্থা",
        "Action": "পদক্ষেপ",
        "Enrolled": "নিবন্ধিত",
        "Full": "পূর্ণ",
        "Enroll": "নিবন্ধন করুন",
        "Start": "শুরু করুন",
        "Submitted": "জমা দেওয়া হয়েছে",
        "Time Limit": "সময়সীমা",
        "Questions": "প্রশ্নসমূহ",
        "Time Left": "অবশিষ্ট সময়",
        "Submit Quiz": "কুইজ জমা দিন",
        "Results": "ফলাফল",
        "Score": "স্কোর",
        "out of": "এর মধ্যে",
        "Excellent": "চমৎকার",
        "Good Job": "ভালো কাজ",
        "Keep Studying": "পড়াশোনা চালিয়ে যান",
        "Better Luck Next Time": "পরের বার আরও ভালো হবে",
        "No quizzes available": "কোন কুইজ উপলব্ধ নেই",
        "No courses are currently available in the database": "ডাটাবেজে বর্তমানে কোনো কোর্স উপলব্ধ নেই",

        // Routine page
        "Routine Module": "রুটিন মডিউল",
        "Weekly Class Routine": "সাপ্তাহিক ক্লাসের রুটিন",
        "Class Routine": "ক্লাস রুটিন",
        "Day": "দিন",
        "Time": "সময়",
        "Room": "কক্ষ",
        "Subject": "বিষয়",
        "Course": "কোর্স",
        "Monday": "সোমবার",
        "Tuesday": "মঙ্গলবার",
        "Wednesday": "বুধবার",
        "Thursday": "বৃহস্পতিবার",
        "Friday": "শুক্রবার",
        "Saturday": "শনিবার",
        "Sunday": "রবিবার",

        // Advising page
        "Advising Module": "অ্যাডভাইজিং মডিউল",
        "View available courses and section statuses to plan your enrollment.": "আপনার নিবন্ধন পরিকল্পনা করতে উপলব্ধ কোর্স এবং সেকশন অবস্থা দেখুন।",
        "View available courses and section statuses to plan your enrollment": "আপনার নিবন্ধন পরিকল্পনা করতে উপলব্ধ কোর্স এবং সেকশন অবস্থা দেখুন",
        "Instructor": "প্রভাষক",
        "Credits": "ক্রেডিট",
        "Marks Distribution": "নম্বর বণ্টন",
        "Available Sections": "উপলব্ধ সেকশনসমূহ",
        "No sections have been created for this course yet.": "এই কোর্সের জন্য এখনও কোনো সেকশন তৈরি করা হয়নি।",
        "No sections have been created for this course yet": "এই কোর্সের জন্য এখনও কোনো সেকশন তৈরি করা হয়নি",
        "Sec": "সেকশন",
        "Theory Schedule": "তত্ত্বীয় সময়সূচী",
        "Lab Schedule": "ল্যাব সময়সূচী",
        "Seats Available": "উপলব্ধ আসন",

        // Grade Sheet page
        "Academic Transcript": "অ্যাকাডেমিক ট্রান্সক্রিপ্ট",
        "Course Code": "কোর্স কোড",
        "Grade": "গ্রেড",
        "Grade Points": "গ্রেড পয়েন্ট",
        "GPA": "জিপিএ",
        "CGPA": "সিজিপিএ",
        "Semester": "সেমিস্টার",
        "Marks": "নম্বর",
        "Total Marks": "মোট নম্বর",

        // Assignments page
        "My Assignments": "আমার অ্যাসাইনমেন্ট",
        "Published": "প্রকাশিত",
        "Deadline": "সময়সীমা",
        "Upload": "আপলোড করুন",
        "Choose File": "ফাইল নির্বাচন করুন",
        "No File Chosen": "কোন ফাইল নির্বাচিত নেই",
        "Upload Assignment": "অ্যাসাইনমেন্ট আপলোড করুন",
        "Click a course to view, download, and submit your assignments.": "আপনার অ্যাসাইনমেন্ট দেখতে, ডাউনলোড করতে এবং জমা দিতে একটি কোর্সে ক্লিক করুন।",
        "No assignments have been deployed for your enrolled courses yet.": "আপনার নিবন্ধিত কোর্সের জন্য এখনও কোনো অ্যাসাইনমেন্ট দেওয়া হয়নি।",

        // Quizzes page
        "My Quizzes": "আমার কুইজ",
        "Select a quiz to start answering.": "উত্তর দেওয়া শুরু করতে একটি কুইজ নির্বাচন করুন।",
        "Select a quiz to start answering": "উত্তর দেওয়া শুরু করতে একটি কুইজ নির্বাচন করুন",

        // Login page
        "System Sign In": "সিস্টেম সাইন ইন",
        "Verify clearance to access secure repository": "সুরক্ষিত রিপোজিটরি অ্যাক্সেস করতে অনুমতি যাচাই করুন",
        "Institutional Email or ID": "প্রাতিষ্ঠানিক ইমেল বা আইডি",
        "Password": "পাসওয়ার্ড",
        "Initiate Connection": "সংযোগ শুরু করুন",
        "Unauthorized access is logged.": "অননুমোদিত প্রবেশ লগ করা হয়।",
        "Unauthorized access is logged": "অননুমোদিত প্রবেশ লগ করা হয়",
        "Establish Credentials": "শংসাপত্র তৈরি করুন",

        // Register page
        "Create Account": "অ্যাকাউন্ট তৈরি করুন",
        "Join the secure thesis network portal": "সুরক্ষিত থিসিস নেটওয়ার্ক পোর্টালে যোগ দিন",
        "Select Department": "বিভাগ নির্বাচন করুন",
        "Already registered?": "ইতিমধ্যে নিবন্ধিত?",
        "Access Terminal": "টার্মিনাল অ্যাক্সেস করুন"
    };

    // Helper function to apply/revert translation
    function applyTranslation(lang) {
        if (lang === 'bn') {
            // Translate text nodes recursively
            const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null, false);
            let node;
            while (node = walker.nextNode()) {
                const parentTagName = node.parentElement ? node.parentElement.tagName.toUpperCase() : '';
                if (parentTagName === 'SCRIPT' || parentTagName === 'STYLE' || parentTagName === 'TEXTAREA') {
                    continue;
                }

                const trimmed = node.nodeValue.trim();
                if (trimmed) {
                    if (dictionary[trimmed]) {
                        if (node._originalValue === undefined) {
                            node._originalValue = node.nodeValue;
                        }
                        node.nodeValue = node.nodeValue.replace(trimmed, dictionary[trimmed]);
                    }
                }
            }

            // Translate placeholders & inputs
            document.querySelectorAll('input, textarea').forEach(el => {
                if (el.placeholder) {
                    const trimmed = el.placeholder.trim();
                    if (dictionary[trimmed]) {
                        if (el._originalPlaceholder === undefined) {
                            el._originalPlaceholder = el.placeholder;
                        }
                        el.placeholder = dictionary[trimmed];
                    }
                }
                if ((el.type === 'submit' || el.type === 'button') && el.value) {
                    const trimmed = el.value.trim();
                    if (dictionary[trimmed]) {
                        if (el._originalValueAttr === undefined) {
                            el._originalValueAttr = el.value;
                        }
                        el.value = dictionary[trimmed];
                    }
                }
            });
        } else {
            // Revert back to English
            // Restore text nodes
            const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null, false);
            let node;
            while (node = walker.nextNode()) {
                if (node._originalValue !== undefined) {
                    node.nodeValue = node._originalValue;
                }
            }

            // Restore inputs
            document.querySelectorAll('input, textarea').forEach(el => {
                if (el._originalPlaceholder !== undefined) {
                    el.placeholder = el._originalPlaceholder;
                }
                if (el._originalValueAttr !== undefined) {
                    el.value = el._originalValueAttr;
                }
            });
        }
    }

    // ── Language Toggle Setup ──
    const currentLang = localStorage.getItem('lang') || 'en';
    if (currentLang === 'bn') {
        applyTranslation('bn');
    }

    const langToggle = document.getElementById('langToggleBtn');
    if (langToggle) {
        langToggle.checked = (currentLang === 'bn');
        langToggle.addEventListener('change', () => {
            const isBangla = langToggle.checked;
            const newLang = isBangla ? 'bn' : 'en';
            localStorage.setItem('lang', newLang);
            applyTranslation(newLang);
        });
    }

    // ── Dyslexia Font Toggle Setup ──
    const currentDyslexia = localStorage.getItem('dyslexia') || 'disabled';
    const dyslexiaToggle = document.getElementById('dyslexiaToggleBtn');
    if (dyslexiaToggle) {
        dyslexiaToggle.checked = (currentDyslexia === 'enabled');
        dyslexiaToggle.addEventListener('change', () => {
            const isEnabled = dyslexiaToggle.checked;
            if (isEnabled) {
                document.documentElement.classList.add('dyslexia-font');
                localStorage.setItem('dyslexia', 'enabled');
            } else {
                document.documentElement.classList.remove('dyslexia-font');
                localStorage.setItem('dyslexia', 'disabled');
            }
        });
    }

    function initUniversalSearchNav() {
        const navbar = document.querySelector('.top-navbar');
        if (!navbar || navbar.querySelector('.universal-search-container')) return;

        const navRight = navbar.querySelector('.navbar-right');
        const navLeft = navbar.querySelector('.navbar-left');
        if (!navRight || !navLeft) return;

        const container = document.createElement('div');
        container.className = 'universal-search-container';
        container.innerHTML = `
            <div class="universal-search-field">
                <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                <input type="text" id="universalSearchInput" class="universal-search-input" placeholder="Search messages, materials, routine, features..." autocomplete="off">
                <button type="button" id="searchFilterToggle" class="search-filter-toggle" aria-label="Open search filters" aria-expanded="false">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 6h16"></path>
                        <path d="M4 12h10"></path>
                        <path d="M4 18h16"></path>
                    </svg>
                </button>
                <div id="searchFilterPanel" class="search-filter-panel">
                    <div class="search-filter-title">Search Type</div>
                    <div class="search-chip-list">
                        <button type="button" class="search-chip active" data-filter="features">App Features</button>
                        <button type="button" class="search-chip" data-filter="messages">Messages</button>
                        <button type="button" class="search-chip" data-filter="materials">Course Materials</button>
                        <button type="button" class="search-chip" data-filter="routine">Routine</button>
                    </div>
                </div>
                <div id="universalSearchDropdown" class="search-dropdown"></div>
            </div>
        `;

        navbar.insertBefore(container, navRight);

        const searchInput = container.querySelector('#universalSearchInput');
        const searchDropdown = container.querySelector('#universalSearchDropdown');
        const searchFilterToggle = container.querySelector('#searchFilterToggle');
        const searchFilterPanel = container.querySelector('#searchFilterPanel');
        const searchFilterChips = container.querySelectorAll('.search-chip');
        let searchTimeout = null;
        let selectedSearchFilter = 'features';

        const searchFilterLabels = {
            messages: 'Messages',
            materials: 'Course Materials',
            routine: 'Routine',
            features: 'App Features'
        };

        const getSearchIcon = (type) => {
            if (type === 'Feature') {
                return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>';
            }
            if (type === 'Message') {
                return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
            }
            if (type === 'Course Material') {
                return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
            }
            if (type === 'Routine') {
                return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>';
            }
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16"><circle cx="12" cy="12" r="10"/></svg>';
        };

        const renderSearchResults = (data) => {
            if (!searchDropdown || !data || data.length === 0) {
                if (searchDropdown) {
                    searchDropdown.innerHTML = '<div class="search-loading">No results found.</div>';
                }
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
                fetch(`universal_search.php?q=${encodeURIComponent(query)}&filter=${encodeURIComponent(selectedSearchFilter)}`)
                    .then(response => response.json())
                    .then(renderSearchResults)
                    .catch(() => {
                        searchDropdown.innerHTML = '<div class="search-loading">Error fetching results.</div>';
                    });
            }, 200);
        };

        searchInput.addEventListener('input', runSearch);
        searchInput.addEventListener('focus', function() {
            if (this.value.trim().length > 0 && searchDropdown.innerHTML !== '') {
                searchDropdown.classList.add('active');
            }
        });

        if (searchFilterToggle && searchFilterPanel) {
            searchFilterToggle.addEventListener('click', function(e) {
                e.stopPropagation();
                const isOpen = searchFilterPanel.classList.toggle('active');
                searchFilterToggle.classList.toggle('active', isOpen);
                searchFilterToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });
        }

        searchFilterChips.forEach(chip => {
            chip.addEventListener('click', function(e) {
                e.stopPropagation();
                selectedSearchFilter = this.dataset.filter;
                searchFilterToggle?.setAttribute('aria-label', `Search type: ${searchFilterLabels[selectedSearchFilter] || 'App Features'}`);
                searchFilterChips.forEach(btn => btn.classList.remove('active'));
                this.classList.add('active');
                searchFilterPanel.classList.remove('active');
                searchFilterToggle.classList.remove('active');
                searchFilterToggle.setAttribute('aria-expanded', 'false');
                if (searchInput.value.trim().length > 0) {
                    runSearch();
                }
            });
        });

        document.addEventListener('click', function(e) {
            if (!container.contains(e.target)) {
                searchDropdown.classList.remove('active');
                searchFilterPanel.classList.remove('active');
                searchFilterToggle?.classList.remove('active');
                searchFilterToggle?.setAttribute('aria-expanded', 'false');
            }
        });
    }

    // Auto-dismiss alert boxes after 3 seconds
    document.querySelectorAll('.alert-box.alert-success, .alert-box.alert-error').forEach(function(el) {
        setTimeout(function() {
            el.style.transition = 'opacity 0.5s ease-out, transform 0.5s ease-out, max-height 0.5s ease-out, margin 0.5s ease-out, padding 0.5s ease-out';
            el.style.opacity = '0';
            el.style.transform = 'translateY(-10px)';
            
            setTimeout(function() {
                el.style.maxHeight = '0px';
                el.style.padding = '0px';
                el.style.margin = '0px';
                el.style.border = 'none';
                setTimeout(function() {
                    el.remove();
                }, 500);
            }, 500);
        }, 3000);
    });

    initUniversalSearchNav();
});
