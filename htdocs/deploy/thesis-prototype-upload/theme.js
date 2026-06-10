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
});
