Automated Question Generator
🚀 A smart exam management system for teachers and students
The Automated Question Generator helps teachers create, manage, and grade exams effortlessly while allowing students to take exams online with real-time results.

✨ Key Features
For Teachers
✅ Create Exams – Set up exams with deadlines and multiple question types (MCQ, text, etc.)✅ Generate Questions – Use predefined templates from question_templates.json to generate questions based on topics✅ Assign Questions – Easily add questions to exams✅ View Results – Track student performance with detailed analytics✅ Republish Exams – Reset submissions for retakes  
For Students
📝 Take Exams Online – Timed exams with auto-submission📊 Instant Results – See scores and correct answers immediately📚 Exam History – Review past attempts and progress  

🛠 Tech Stack

Backend: PHP, MySQL  
Frontend: Bootstrap 5, JavaScript  
Question Generation: JSON-based templates (question_templates.json)


🚀 Quick Setup
Requirements

PHP 8.0+  
MySQL 5.7+  
Web server (e.g., Apache via XAMPP)  
Composer (for dependencies)

Installation

Clone the Repository
git clone https://github.com/r-ajeshbehera/automated_question_generator
cd automated_question_generator


Set Up the Database

Create a MySQL database named automated_exam_portal.
Import the schema and dummy data:mysql -u your_username -p automated_exam_portal < setup.sql




Install Dependencies
composer install


Configure Database

Copy config/database.php.sample to config/database.php.
Update config/database.php with your MySQL credentials:define('DB_HOST', 'localhost');
define('DB_USER', 'your_username');
define('DB_PASS', 'your_password');
define('DB_NAME', 'automated_exam_portal');




Set Up Web Server

Place the project in your web server’s root (e.g., C:\xampp\htdocs\my_php_projects\automated_question_generator).
Ensure .htaccess is enabled for URL rewriting.


Run the Application

Start Apache and MySQL (e.g., via XAMPP).
Access via http://localhost/my_php_projects/automated_question_generator/.



Dummy Data for Testing

Teacher Login: Username: teacher1, Password: teacher123
Student Login: Username: student1, Password: student123
Two sample exams: "Sample Math Exam" and "Sample Science Exam".
Three MCQ questions (e.g., "What is the value of 2 + 2?") with options.
One exam submission and answer for testing.


📂 Project Structure
automated_question_generator/
├── auth/                    # Login, logout, registration
├── config/                  # Database settings
├── includes/                # Core functions & headers
├── student/                 # Student dashboard & exams
├── teacher/                 # Teacher tools (create exams, view results)
├── assets/                  # CSS, JS, and images
├── question_templates.json  # JSON templates for question generation
└── vendor/                  # Composer dependencies


📜 How It Works
Teacher Flow

Log in → Create an exam (teacher/create_exam.php) → Generate questions from question_templates.json (teacher/generate_questions.php) or add manually → Publish (teacher/publish_exam.php).
Students take the exam → View results (teacher/view_results.php).

Student Flow

Log in → View available exams (student/view_exams.php) → Take exam (student/take_exam.php) → Get instant results.

Question Generation

Questions are generated using predefined templates in question_templates.json, allowing teachers to select topics and create MCQ or text-based questions.


🌐 Live Demo
Try the application live at: https://automatedexamportal.great-site.net/

🤝 Contribution
Want to improve the Automated Question Generator? Feel free to:🔹 Report bugs🔹 Suggest features🔹 Submit pull requests  

📄 License
MIT License – Free to use and modify.

Happy Testing! 🎉
📧 Contact: rb450637@gmail.com🌐 Live Demo: https://automatedexamportal.great-site.net/

