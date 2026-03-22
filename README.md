# Endonezyam LMS: Architecture & Backend Showcase

> **Note:** Endonezyam is a proprietary, closed-source EdTech platform currently in production. To protect the intellectual property and core business logic, this repository serves as a **partial architecture showcase**. It contains selected backend controllers, middleware, and service files to demonstrate the system's structural integrity, data handling, and security mechanisms.

## System Overview
Endonezyam is a custom-built Learning Management System (LMS) designed to facilitate Gamified, Sololearn-style language education. The platform handles complex dynamic routing, real-time user state management, multi-tiered access control, and seamless session-to-database progress migration. 

**Core Stack:** PHP, Laravel, Relational Database Architecture.

## Key Architectural Highlights

This showcase highlights how the application handles critical enterprise-level challenges, specifically concurrency, data integrity, and complex middleware gating.

### 1. Concurrency Protection & State Management
**File:** `ProgressController.php`
* **Pessimistic Locking:** Utilizes `->lockForUpdate()` within database transactions when calculating and updating final lesson page counts. This prevents race conditions and database corruption if a user rapidly triggers multiple "next page" requests.
* **Dynamic Routing:** Calculates next-available lessons on the fly based on relational position tracking, rather than hardcoded IDs.

### 2. Seamless UX & Session-to-Database Migration
**File:** `ProgressMigrationService.php` / `LessonController.php`
* **Frictionless Onboarding:** Allows guest users to immediately access entry-level courses without registering. Progress is temporarily mapped and stored in the browser session.
* **Transactional Migration:** Upon user registration, a `DB::transaction` seamlessly intercepts the session array and writes the guest's progress directly into the `LessonProgress` relational database tables, ensuring zero data loss during conversion.

### 3. "The Fortress" Gating & Security
**File:** `CheckLessonAccess.php` (Middleware)
* **Progress-Aware Routing:** This middleware doesn't just check authentication; it actively queries the database (or session cache) for the user's latest lesson state. 
* **Exploit Prevention:** Automatically blocks users from manually manipulating URL parameters (e.g., typing `?page=5`) to access content beyond their actual completed progress. 

### 4. Clean MVC Separation
**File:** `ViewServiceProvider.php`
* **View Composers:** Abstracts complex progress-calculation queries out of the main controllers. It intercepts requests and dynamically injects localized variables (`continueURL`, `lesson_title`, `level_percentage`) directly into the blade components, keeping the controllers strictly focused on business logic.

## Contact
For inquiries regarding system architecture, API integrations, or full-stack development, feel free to reach out via [Your LinkedIn Profile URL].
