# Contributing to TalkTherapy

Thank you for your interest in contributing to TalkTherapy! This guide will help you understand our development process and how to make meaningful contributions to our mental health platform.

## 🌟 Project Overview

TalkTherapy is a Laravel and Vue.js-based platform that connects individuals with verified mental health professionals through:

- **Individual Therapy Sessions**: One-on-one therapy with licensed counsellors
- **Group Therapy Sessions**: Collaborative therapy sessions with multiple participants
- **Real-time Communications**: Chat functionality for therapy sessions and discussions
- **Counsellor Verification**: Robust verification system for mental health professionals
- **Session Management**: Scheduling, conducting, and tracking therapy sessions
- **Discussion Forums**: Counsellor-to-counsellor discussions for collaboration

## 🚀 Getting Started

### Prerequisites

- PHP 8.1+
- Node.js 18+
- MySQL 8.0+
- Docker & Docker Compose (recommended)

### Development Setup

1. **Clone the repository**
   ```bash
   git clone https://github.com/mr-robertamoah/TalkTherapy.git
   cd TalkTherapy
   ```

2. **Docker Setup (Recommended)**
   ```bash
   # Copy environment file
   cp .env.docker .env

   # Start containers
   docker compose up -d

   # Install dependencies
   docker exec talktherapy-php composer install
   docker exec talktherapy-vite npm install

   # Run migrations and seed data
   docker exec talktherapy-php php artisan migrate --seed
   ```

3. **Access the application**
   - Frontend: http://localhost:8000
   - Vite Dev Server: http://localhost:5173
   - WebSocket (Reverb): http://localhost:8080

## 🏗️ Architecture Overview

### Backend (Laravel)
- **Models**: Core entities (User, Counsellor, Therapy, Session, Discussion, GroupTherapy)
- **Services**: Business logic layer (TherapyService, CounsellorService, etc.)
- **Actions**: Single-responsibility validation and business operations
- **DTOs**: Data Transfer Objects for clean data handling
- **Real-time**: Laravel Reverb for WebSocket connections

### Frontend (Vue.js 3)
- **Pages**: Main application routes
- **Components**: Reusable UI components
- **Composables**: Shared reactive logic
- **Echo**: Real-time client-side WebSocket handling

### Key Architecture Patterns
- **Service-Action Pattern**: Services orchestrate Actions for business logic
- **DTO Pattern**: Clean data transfer between layers
- **Repository Pattern**: Data access abstraction
- **Event Broadcasting**: Real-time notifications and updates

## 📋 Development Guidelines

### Code Style
- **PHP**: Follow PSR-12 standards
- **JavaScript/Vue**: Use ESLint configuration provided
- **Database**: Use descriptive migration names and follow Laravel conventions

### Git Workflow
1. Create feature branches from `main`: `feature/your-feature-name`
2. Use conventional commits: `feat:`, `fix:`, `docs:`, `style:`, `refactor:`, `test:`
3. Write descriptive commit messages
4. Create pull requests for all changes

### Testing
- Write unit tests for Services and Actions
- Use Feature tests for API endpoints
- Test real-time functionality thoroughly
- Run tests before submitting PRs: `php artisan test`

## 🎯 Contribution Areas

### High-Priority Areas
1. **Group Therapy Enhancement**: Complete group therapy functionality
2. **Mobile Responsiveness**: Improve mobile user experience
3. **Performance Optimization**: Database queries and frontend optimization
4. **Accessibility**: WCAG compliance improvements
5. **Test Coverage**: Expand test suite

### Feature Contributions
- **New Therapy Types**: Additional therapy session formats
- **Payment Integration**: Secure payment processing
- **Advanced Scheduling**: Calendar integration and availability management
- **Analytics Dashboard**: Usage statistics and insights
- **Multi-language Support**: Internationalization

### Documentation
- API documentation improvements
- User guides and tutorials
- Developer documentation
- Code comments and inline documentation

## 🐛 Bug Reports

When reporting bugs, please include:
- **Environment**: Browser, OS, device type
- **Steps to reproduce**: Detailed reproduction steps
- **Expected vs actual behavior**
- **Screenshots/videos** if applicable
- **Console errors** or relevant logs

## 🔧 Development Tips

### Database Structure
- **Users**: Platform users who can become counsellors
- **Counsellors**: Verified mental health professionals
- **Therapies**: Individual therapy relationships
- **GroupTherapies**: Group therapy sessions
- **Sessions**: Individual therapy/discussion sessions
- **Discussions**: Counsellor collaboration spaces
- **Messages**: Real-time chat messages

### Real-time Features
- All chat functionality uses Laravel Reverb
- WebSocket channels for therapies, discussions, and sessions
- Echo client handles real-time updates on frontend

### Key Services
- **TherapyService**: Individual therapy management
- **CounsellorService**: Counsellor verification and management
- **SessionService**: Session scheduling and management
- **MessageService**: Chat and communication handling

## 🚦 Pull Request Process

1. **Fork and Branch**: Create a feature branch from main
2. **Develop**: Implement your changes following our guidelines
3. **Test**: Ensure all tests pass and add new tests if needed
4. **Document**: Update documentation if needed
5. **Submit**: Create a pull request with detailed description

### PR Requirements
- [ ] All tests pass
- [ ] Code follows style guidelines
- [ ] Documentation updated (if applicable)
- [ ] No console errors or warnings
- [ ] Feature works in both individual and group contexts
- [ ] Real-time functionality tested

## 🏷️ Issue Labels

- `bug`: Something isn't working correctly
- `enhancement`: New feature or improvement
- `documentation`: Documentation improvements
- `good first issue`: Good for newcomers
- `help wanted`: Community help needed
- `priority-high`: Critical issues
- `real-time`: WebSocket/real-time related
- `therapy`: Individual therapy features
- `group-therapy`: Group therapy features
- `counsellor`: Counsellor-specific features

## 🤝 Community Guidelines

- **Be respectful**: Maintain professional and inclusive communication
- **Be patient**: Mental health technology requires careful consideration
- **Be thorough**: Test thoroughly, especially real-time features
- **Ask questions**: Don't hesitate to ask for clarification
- **Share knowledge**: Help other contributors learn

## 📚 Resources

### Documentation
- [Laravel Documentation](https://laravel.com/docs)
- [Vue.js Documentation](https://vuejs.org/)
- [Laravel Reverb Documentation](https://laravel.com/docs/reverb)
- [Inertia.js Documentation](https://inertiajs.com/)

### Mental Health Considerations
- Follow HIPAA-like privacy guidelines
- Ensure secure data handling
- Consider accessibility for users with different abilities
- Maintain professional standards for therapy platform

## 🆘 Getting Help

- **GitHub Issues**: For bug reports and feature requests
- **Discussions**: For questions and general discussion
- **Email**: mr_robertamoah@yahoo.com for sensitive matters

## 📝 License

By contributing to TalkTherapy, you agree that your contributions will be licensed under the same license as the project.

---

Thank you for helping us build a platform that makes mental health support more accessible! 🧠💚
