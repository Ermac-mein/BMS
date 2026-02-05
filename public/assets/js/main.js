(function() {
    'use strict';

    // ========================
    // Configuration & Constants
    // ========================
    const CONFIG = {
        endpoints: {
            application: '/backend/submit_application.php',
            contact: '/backend/submit_contact.php'
        },
        timeout: 30000,
        validation: {
            email: /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
            phone: /^[\d\s+\-()]{10,20}$/ // Simplified phone validation
        }
    };

    const ERROR_MESSAGES = {
        network: 'Network error. Please check your internet connection.',
        timeout: 'Request timed out. Please try again.',
        server: 'Server error. Please try again later.',
        validation: 'Please fix the errors in the form.',
        unknown: 'An unexpected error occurred.'
    };

    // ========================
    // State Management
    // ========================
    const state = {
        currentSlide: 0,
        slideInterval: null,
        isMobileMenuOpen: false,
        isModalOpen: false,
        isSubmitting: false,
        galleryImages: [
            'slide1.png',
            'slide2.png',
            'slide3.png',
            'classroom.png'
            // Add more image filenames here as needed
        ],
        currentGallerySlide: 0,
        galleryInterval: null
    };

    // ========================
    // DOM Cache
    // ========================
    const dom = {
        mobileMenuBtn: document.getElementById('mobileMenuBtn'),
        navLinks: document.getElementById('navLinks'),
        applyNavBtn: document.getElementById('applyNavBtn'),
        applicationModal: document.getElementById('applicationModal'),
        closeModalBtn: document.getElementById('closeModal'),
        cancelBtn: document.getElementById('cancelBtn'),
        applicationForm: document.getElementById('applicationForm'),
        contactForm: document.getElementById('contactForm'),
        prevSlide: document.getElementById('prevSlide'),
        nextSlide: document.getElementById('nextSlide'),
        successMessage: document.getElementById('successMessage'),
        header: document.getElementById('header'),
        galleryTrack: document.getElementById('galleryTrack'),
        galleryPrev: document.getElementById('galleryPrev'),
        galleryNext: document.getElementById('galleryNext'),
        galleryIndicators: document.getElementById('galleryIndicators'),
        announcementToast: document.getElementById('announcement-toast'),
        closeAnnouncement: document.getElementById('closeAnnouncement')
    };

    // ========================
    // Utility Functions
    // ========================
    const utils = {
        $: (id) => document.getElementById(id),
        $$: (selector, parent = document) => Array.from(parent.querySelectorAll(selector)),

        debounce: (fn, delay = 100) => {
            let timeoutId;
            return (...args) => {
                clearTimeout(timeoutId);
                timeoutId = setTimeout(() => fn(...args), delay);
            };
        },

        validateEmail: (email) => {
            if (!email) return false;
            return CONFIG.validation.email.test(email.trim());
        },

        validatePhone: (phone) => {
            if (!phone) return true; // Phone is optional
            const cleaned = phone.replace(/\D/g, '');
            return cleaned.length >= 10 && cleaned.length <= 15;
        },

        normalizePhone: (phone) => {
            if (!phone) return '';
            const cleaned = phone.replace(/\D/g, '');
            if (!cleaned) return '';
            
            if (cleaned.startsWith('234') && cleaned.length === 13) {
                return cleaned;
            }
            if (cleaned.startsWith('0') && cleaned.length === 11) {
                return `234${cleaned.substring(1)}`;
            }
            if (cleaned.length === 10) {
                return `234${cleaned}`;
            }
            if (cleaned.length >= 10 && cleaned.length <= 15) {
                return cleaned;
            }
            return phone; // Return as-is if doesn't match patterns
        },

        animateElement: (element, animation, duration = 300) => {
            return new Promise((resolve) => {
                if (!element) {
                    resolve();
                    return;
                }
                element.style.animation = `${animation} ${duration}ms ease`;
                setTimeout(() => {
                    element.style.animation = '';
                    resolve();
                }, duration);
            });
        },

        // Simple field name mapper for contact form
        mapFieldName: (name) => {
            const mappings = {
                'contactName': 'name',
                'contactEmail': 'email',
                'contactPhone': 'phone',
                'contactSubject': 'subject',
                'contactMessage': 'message'
            };
            return mappings[name] || name;
        },

        // Convert FormData to JSON with consistent field names
        formDataToJson: (form) => {
            const data = {};
            const formElements = Array.from(form.elements);
            
            formElements.forEach(element => {
                if (element.name && !element.disabled) {
                    let value;
                    if (element.type === 'checkbox') {
                        value = element.checked;
                    } else if (element.type === 'radio') {
                        if (element.checked) {
                            value = element.value;
                        }
                    } else if (element.type === 'select-multiple') {
                        value = Array.from(element.selectedOptions).map(opt => opt.value);
                    } else {
                        value = element.value.trim();
                    }
                    
                    if (value !== undefined) {
                        // Use consistent field names for contact form
                        const fieldName = utils.mapFieldName(element.name);
                        data[fieldName] = value;
                    }
                }
            });
            
            return data;
        },

        // Convert Application FormData to JSON with correct field mapping
        applicationFormDataToJson: (form) => {
            const data = {};
            const formElements = Array.from(form.elements);
            
            formElements.forEach(element => {
                if (element.name && !element.disabled) {
                    let value;
                    if (element.type === 'checkbox') {
                        value = element.checked;
                    } else if (element.type === 'radio') {
                        if (element.checked) {
                            value = element.value;
                        }
                    } else if (element.type === 'select-multiple') {
                        value = Array.from(element.selectedOptions).map(opt => opt.value);
                    } else {
                        value = element.value.trim();
                    }
                    
                    if (value !== undefined) {
                        // Map application form field names exactly as PHP expects
                        data[element.name] = value;
                    }
                }
            });
            
            return data;
        },

        // Get browser request headers for debugging
        logRequestHeaders: (endpoint, data, headers) => {
            console.group('Request Details');
            console.log('Endpoint:', endpoint);
            console.log('Method: POST');
            console.log('Content-Type:', headers['Content-Type']);
            console.log('Payload:', data);
            console.log('Payload Type:', typeof data);
            console.log('Is JSON:', headers['Content-Type'] === 'application/json');
            console.groupEnd();
        }
    };

    // ========================
    // Toast Notification System
    // ========================
    const Toast = {
        show: (type, title, message, duration = 4000) => {
            let container = document.getElementById('bm-toast-container');
            if (!container) {
                container = document.createElement('div');
                container.id = 'bm-toast-container';
                container.style.cssText = `
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    z-index: 999999;
                    display: flex;
                    flex-direction: column;
                    gap: 12px;
                    max-width: 400px;
                    pointer-events: none;
                `;
                document.body.appendChild(container);
            }

            // Special handling for welcome toast
            if (title === 'Welcome') {
                type = 'welcome';
            }

            const colors = {
                success: { 
                    bg: '#d1fae5', 
                    border: '#10b981',
                    icon: '✓',
                    iconColor: '#059669'
                },
                error: { 
                    bg: '#fee2e2', 
                    border: '#ef4444',
                    icon: '✗',
                    iconColor: '#dc2626'
                },
                warning: { 
                    bg: '#fef3c7', 
                    border: '#f59e0b',
                    icon: '⚠',
                    iconColor: '#d97706'
                },
                info: { 
                    bg: '#ffffff', 
                    border: '#e5e7eb',
                    icon: 'ℹ',
                    iconColor: '#4b5563'
                },
                welcome: { 
                    bg: '#ffffff', 
                    border: '#e5e7eb',
                    icon: '🏫',
                    iconColor: '#4b5563'
                }
            };

            const config = colors[type] || colors.info;

            const toast = document.createElement('div');
            toast.className = `bm-toast bm-toast-${type}`;
            toast.style.cssText = `
                background: ${config.bg};
                border: 1px solid ${config.border};
                border-left: 4px solid ${config.border};
                color: #1f2937;
                padding: 18px 22px;
                border-radius: 10px;
                box-shadow: 0 12px 28px rgba(0,0,0,0.15);
                opacity: 0;
                transform: translateX(120px);
                transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                pointer-events: auto;
                display: flex;
                align-items: flex-start;
                gap: 14px;
                max-width: 380px;
                animation: toastSlideIn 0.3s ease forwards;
            `;

            const icon = document.createElement('div');
            icon.style.cssText = `
                font-size: 22px;
                font-weight: bold;
                color: ${config.iconColor};
                flex-shrink: 0;
                margin-top: 2px;
            `;
            icon.textContent = config.icon;

            const content = document.createElement('div');
            content.style.cssText = `
                flex: 1;
                min-width: 0;
            `;

            const titleEl = document.createElement('div');
            titleEl.style.cssText = `
                font-weight: 600;
                font-size: 16px;
                margin-bottom: 5px;
                color: ${type === 'welcome' ? '#4b5563' : config.iconColor};
            `;
            titleEl.textContent = title;

            const messageEl = document.createElement('div');
            messageEl.style.cssText = `
                font-size: 15px;
                line-height: 1.5;
                color: ${type === 'welcome' ? '#6b7280' : '#374151'};
            `;
            messageEl.textContent = message;

            const closeBtn = document.createElement('button');
            closeBtn.innerHTML = '&times;';
            closeBtn.style.cssText = `
                background: none;
                border: none;
                color: ${type === 'welcome' ? '#6b7280' : '#374151'};
                font-size: 22px;
                line-height: 1;
                cursor: pointer;
                padding: 0;
                margin: -8px -8px 0 0;
                flex-shrink: 0;
                transition: color 0.2s;
            `;
            closeBtn.addEventListener('click', () => {
                Toast.removeToast(toast);
            });

            content.appendChild(titleEl);
            content.appendChild(messageEl);
            toast.appendChild(icon);
            toast.appendChild(content);
            toast.appendChild(closeBtn);
            container.appendChild(toast);

            requestAnimationFrame(() => {
                toast.style.opacity = '1';
                toast.style.transform = 'translateX(0)';
            });

            const timeoutId = setTimeout(() => {
                Toast.removeToast(toast);
            }, duration);

            toast.dataset.timeoutId = timeoutId;
        },

        removeToast: (toast) => {
            if (!toast) return;
            clearTimeout(toast.dataset.timeoutId);
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(120px)';
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        }
    };

    // ========================
    // Announcement Handler
    // ========================
    class Announcement {
        constructor() {
            this.init();
        }

        init() {
            if (!dom.announcementToast) return;
            
            // Show welcome announcement immediately
            setTimeout(() => {
                Toast.show('info', 'Welcome', 'Welcome to Beautiful Minds Schools!');
            }, 1000);
            
            // Show admission announcement after 1 minute
            setTimeout(() => {
                this.show();
            }, 30000); // 30 seconds = 30000 milliseconds
            
            this.setupEventListeners();
        }

        show() {
            if (!dom.announcementToast) return;
            
            dom.announcementToast.classList.add('show');
            utils.animateElement(dom.announcementToast, 'slideIn', 500);
            
            // Auto hide after 10 seconds
            setTimeout(() => {
                this.hide();
            }, 10000);
        }

        hide() {
            if (!dom.announcementToast) return;
            
            dom.announcementToast.classList.remove('show');
            utils.animateElement(dom.announcementToast, 'slideOut', 500);
        }

        setupEventListeners() {
            dom.closeAnnouncement?.addEventListener('click', () => this.hide());
        }
    }

    // ========================
    // Mobile Menu Handler
    // ========================
    class MobileMenu {
        constructor() {
            this.init();
        }

        init() {
            if (!dom.mobileMenuBtn || !dom.navLinks) return;
            
            this.updateMobileUI();
            this.setupEventListeners();
        }

        updateMobileUI() {
            const isMobile = window.innerWidth <= 768;
            
            if (isMobile) {
                dom.mobileMenuBtn.style.display = 'flex';
                if (dom.applyNavBtn) dom.applyNavBtn.style.display = 'none';
            } else {
                dom.mobileMenuBtn.style.display = 'none';
                if (dom.applyNavBtn) dom.applyNavBtn.style.display = 'inline-flex';
                this.close();
            }
        }

        setupEventListeners() {
            dom.mobileMenuBtn?.addEventListener('click', (e) => this.toggle(e));
            
            document.addEventListener('click', (e) => {
                if (state.isMobileMenuOpen && 
                    !dom.navLinks.contains(e.target) && 
                    !dom.mobileMenuBtn.contains(e.target)) {
                    this.close();
                }
            });
            
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && state.isMobileMenuOpen) {
                    this.close();
                }
            });
            
            dom.navLinks?.querySelectorAll('a').forEach(link => {
                link.addEventListener('click', () => this.close());
            });
            
            window.addEventListener('resize', utils.debounce(() => this.updateMobileUI(), 150));
        }

        toggle(e) {
            if (e) {
                e.preventDefault();
                e.stopPropagation();
            }
            
            if (state.isMobileMenuOpen) {
                this.close();
            } else {
                this.open();
            }
        }

        open() {
            state.isMobileMenuOpen = true;
            dom.navLinks.classList.add('active');
            dom.mobileMenuBtn.innerHTML = '<i class="fas fa-times"></i>';
            document.body.style.overflow = 'hidden';
            utils.animateElement(dom.navLinks, 'slideDown', 300);
        }

        close() {
            state.isMobileMenuOpen = false;
            dom.navLinks.classList.remove('active');
            dom.mobileMenuBtn.innerHTML = '<i class="fas fa-bars"></i>';
            document.body.style.overflow = 'auto';
        }
    }

    // ========================
    // Header Scroll Effect
    // ========================
    class HeaderScroll {
        constructor() {
            this.init();
        }

        init() {
            if (!dom.header) return;
            
            this.updateHeader();
            window.addEventListener('scroll', utils.debounce(() => this.updateHeader(), 10));
        }

        updateHeader() {
            const scrolled = window.scrollY > 50;
            dom.header.classList.toggle('scrolled', scrolled);
            
            if (scrolled) {
                dom.header.style.transform = 'translateY(0)';
                dom.header.style.boxShadow = '0 4px 24px rgba(0, 0, 0, 0.12)';
            } else {
                dom.header.style.transform = 'translateY(0)';
                dom.header.style.boxShadow = '0 2px 20px rgba(0, 0, 0, 0.1)';
            }
        }
    }

    // ========================
    // Hero Slider
    // ========================
    class HeroSlider {
        constructor() {
            this.slides = utils.$$('.slide');
            this.dots = utils.$$('.slider-dot');
            this.init();
        }

        init() {
            if (this.slides.length === 0) return;
            
            this.showSlide(0);
            this.startAutoSlide();
            this.setupEventListeners();
        }

        showSlide(index) {
            if (index >= this.slides.length) state.currentSlide = 0;
            else if (index < 0) state.currentSlide = this.slides.length - 1;
            else state.currentSlide = index;
            
            this.slides.forEach((slide, i) => {
                if (i === state.currentSlide) {
                    slide.classList.add('active');
                    slide.style.opacity = '1';
                    slide.style.zIndex = '1';
                    const slideContent = slide.querySelector('.slide-content');
                    if (slideContent) {
                        slideContent.style.animation = 'slideUp 0.8s ease 0.3s both';
                    }
                } else {
                    slide.classList.remove('active');
                    slide.style.opacity = '0';
                    slide.style.zIndex = '0';
                }
            });
            
            this.dots.forEach((dot, i) => {
                dot.classList.toggle('active', i === state.currentSlide);
                if (i === state.currentSlide) {
                    utils.animateElement(dot, 'pulse', 600);
                }
            });
        }

        nextSlide() {
            this.showSlide(state.currentSlide + 1);
        }

        prevSlide() {
            this.showSlide(state.currentSlide - 1);
        }

        startAutoSlide() {
            clearInterval(state.slideInterval);
            state.slideInterval = setInterval(() => this.nextSlide(), 6000);
        }

        stopAutoSlide() {
            clearInterval(state.slideInterval);
        }

        setupEventListeners() {
            dom.prevSlide?.addEventListener('click', () => {
                this.prevSlide();
                this.startAutoSlide();
                utils.animateElement(dom.prevSlide, 'pulse', 300);
            });
            
            dom.nextSlide?.addEventListener('click', () => {
                this.nextSlide();
                this.startAutoSlide();
                utils.animateElement(dom.nextSlide, 'pulse', 300);
            });
            
            this.dots.forEach((dot, index) => {
                dot.addEventListener('click', () => {
                    this.showSlide(index);
                    this.startAutoSlide();
                    utils.animateElement(dot, 'pulse', 300);
                });
            });
            
            const heroSlider = document.querySelector('.hero-slider');
            if (heroSlider) {
                heroSlider.addEventListener('mouseenter', () => this.stopAutoSlide());
                heroSlider.addEventListener('mouseleave', () => this.startAutoSlide());
                heroSlider.addEventListener('touchstart', () => this.stopAutoSlide());
                heroSlider.addEventListener('touchend', () => setTimeout(() => this.startAutoSlide(), 3000));
            }
        }
    }

    // ========================
    // Gallery Slider
    // ========================
    class GallerySlider {
        constructor() {
            this.init();
        }

        init() {
            if (!dom.galleryTrack) return;
            
            this.loadGalleryImages();
            this.setupEventListeners();
            this.startAutoSlide();
        }

        loadGalleryImages() {
            if (!dom.galleryTrack || state.galleryImages.length === 0) return;
            
            // Clear existing slides
            dom.galleryTrack.innerHTML = '';
            dom.galleryIndicators.innerHTML = '';
            
            // Create slides
            state.galleryImages.forEach((image, index) => {
                const slide = document.createElement('div');
                slide.className = 'gallery-slide';
                slide.innerHTML = `
                    <img src="assets/images/${image}" alt="Gallery Image ${index + 1}" loading="lazy">
                `;
                dom.galleryTrack.appendChild(slide);
                
                // Create indicator dot
                const indicator = document.createElement('div');
                indicator.className = 'gallery-indicator';
                if (index === 0) indicator.classList.add('active');
                indicator.dataset.index = index;
                indicator.addEventListener('click', () => this.goToSlide(index));
                dom.galleryIndicators.appendChild(indicator);
            });
            
            this.updateGallery();
        }

        updateGallery() {
            if (!dom.galleryTrack) return;
            
            const slideWidth = dom.galleryTrack.clientWidth / state.galleryImages.length;
            
            dom.galleryTrack.style.transform = `translateX(-${state.currentGallerySlide * slideWidth}px)`;
            
            // Update indicators
            const indicators = utils.$$('.gallery-indicator');
            indicators.forEach((indicator, index) => {
                indicator.classList.toggle('active', index === state.currentGallerySlide);
                if (index === state.currentGallerySlide) {
                    utils.animateElement(indicator, 'pulse', 400);
                }
            });
        }

        nextSlide() {
            state.currentGallerySlide = (state.currentGallerySlide + 1) % state.galleryImages.length;
            this.updateGallery();
        }

        prevSlide() {
            state.currentGallerySlide = (state.currentGallerySlide - 1 + state.galleryImages.length) % state.galleryImages.length;
            this.updateGallery();
        }

        goToSlide(index) {
            if (index >= 0 && index < state.galleryImages.length) {
                state.currentGallerySlide = index;
                this.updateGallery();
            }
        }

        startAutoSlide() {
            clearInterval(state.galleryInterval);
            state.galleryInterval = setInterval(() => {
                this.nextSlide();
            }, 5000); // Change slide every 5 seconds
        }

        stopAutoSlide() {
            clearInterval(state.galleryInterval);
        }

        setupEventListeners() {
            dom.galleryPrev?.addEventListener('click', () => {
                this.prevSlide();
                this.startAutoSlide();
                utils.animateElement(dom.galleryPrev, 'pulse', 300);
            });
            
            dom.galleryNext?.addEventListener('click', () => {
                this.nextSlide();
                this.startAutoSlide();
                utils.animateElement(dom.galleryNext, 'pulse', 300);
            });
            
            // Touch support for mobile
            let startX = 0;
            let isDragging = false;
            
            const galleryContainer = document.querySelector('.gallery-slider');
            if (galleryContainer) {
                galleryContainer.addEventListener('touchstart', (e) => {
                    startX = e.touches[0].clientX;
                    isDragging = true;
                    this.stopAutoSlide();
                });
                
                galleryContainer.addEventListener('touchmove', (e) => {
                    if (!isDragging) return;
                    const currentX = e.touches[0].clientX;
                    const diff = startX - currentX;
                    
                    if (Math.abs(diff) > 50) {
                        if (diff > 0) {
                            this.nextSlide();
                        } else {
                            this.prevSlide();
                        }
                        isDragging = false;
                    }
                });
                
                galleryContainer.addEventListener('touchend', () => {
                    isDragging = false;
                    this.startAutoSlide();
                });
            }
            
            // Pause auto-slide on hover
            if (galleryContainer) {
                galleryContainer.addEventListener('mouseenter', () => this.stopAutoSlide());
                galleryContainer.addEventListener('mouseleave', () => this.startAutoSlide());
            }
        }
    }

    // ========================
    // Curriculum Tabs
    // ========================
    class CurriculumTabs {
        constructor() {
            this.tabBtns = utils.$$('.tab-btn');
            this.tabContents = utils.$$('.tab-content');
            this.init();
        }

        init() {
            if (this.tabBtns.length === 0) return;
            
            if (this.tabBtns[0]) {
                this.showTab(this.tabBtns[0].dataset.tab);
            }
            
            this.tabBtns.forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.showTab(btn.dataset.tab);
                });
            });
        }

        showTab(tabId) {
            this.tabBtns.forEach(b => {
                b.classList.remove('active');
                if (b.dataset.tab === tabId) {
                    b.classList.add('active');
                    utils.animateElement(b, 'pulse', 300);
                }
            });
            
            this.tabContents.forEach(content => {
                content.classList.remove('active');
                if (content.id === `${tabId}-tab`) {
                    content.classList.add('active');
                    utils.animateElement(content, 'fadeIn', 400);
                }
            });
        }
    }

    // ========================
    // Application Modal
    // ========================
    class Modal {
        static async open() {
            if (!dom.applicationModal || state.isModalOpen || state.isSubmitting) return;
            
            state.isModalOpen = true;
            dom.applicationModal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            document.documentElement.style.overflow = 'hidden';
            
            dom.applicationModal.offsetHeight;
            
            dom.applicationModal.style.opacity = '1';
            dom.applicationModal.style.pointerEvents = 'auto';
            
            await utils.animateElement(dom.applicationModal, 'fadeIn', 200);
            
            const modalContent = dom.applicationModal.querySelector('.modal-content');
            if (modalContent) {
                modalContent.style.transform = 'translateY(0)';
                modalContent.style.opacity = '1';
                await utils.animateElement(modalContent, 'slideUp', 400);
            }
            
            if (dom.applicationForm) {
                dom.applicationForm.style.display = 'block';
                dom.applicationForm.reset();
                
                const fields = dom.applicationForm.querySelectorAll('.is-valid, .is-invalid');
                fields.forEach(field => field.classList.remove('is-valid', 'is-invalid'));
                
                const feedbacks = dom.applicationForm.querySelectorAll('.form-feedback');
                feedbacks.forEach(feedback => feedback.remove());
            }
            
            if (dom.successMessage) {
                dom.successMessage.style.display = 'none';
                dom.successMessage.style.opacity = '0';
            }
        }

        static async close() {
            if (!dom.applicationModal || !state.isModalOpen) return;
            
            const modalContent = dom.applicationModal.querySelector('.modal-content');
            if (modalContent) {
                modalContent.style.transform = 'translateY(-20px)';
                modalContent.style.opacity = '0';
            }
            
            dom.applicationModal.style.opacity = '0';
            dom.applicationModal.style.pointerEvents = 'none';
            
            await new Promise(resolve => setTimeout(resolve, 300));
            
            dom.applicationModal.style.display = 'none';
            document.body.style.overflow = 'auto';
            document.documentElement.style.overflow = 'auto';
            state.isModalOpen = false;
        }

        static init() {
            const applyButtons = utils.$$('.apply-btn');
            
            applyButtons.forEach(btn => {
                btn.addEventListener('click', (e) => {
                    if (e) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                    Modal.open();
                });
            });
            
            dom.applyNavBtn?.addEventListener('click', (e) => {
                if (e) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                Modal.open();
            });
            
            dom.closeModalBtn?.addEventListener('click', (e) => {
                if (e) e.preventDefault();
                Modal.close();
            });
            
            dom.cancelBtn?.addEventListener('click', (e) => {
                if (e) e.preventDefault();
                Modal.close();
            });
            
            window.addEventListener('click', (e) => {
                if (e.target === dom.applicationModal) {
                    Modal.close();
                }
            });
            
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && state.isModalOpen) {
                    Modal.close();
                }
            });
        }
    }

    // ========================
    // Smooth Scrolling
    // ========================
    class SmoothScrolling {
        constructor() {
            this.init();
        }

        init() {
            utils.$$('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', (e) => this.handleClick(e, anchor));
            });
        }

        handleClick(e, anchor) {
            const href = anchor.getAttribute('href');
            if (href === '#' || href === '#!') return;
            
            const target = document.getElementById(href.substring(1));
            if (!target) return;
            
            e.preventDefault();
            
            utils.$$('.nav-links a').forEach(link => link.classList.remove('active'));
            anchor.classList.add('active');
            
            const headerHeight = dom.header ? dom.header.offsetHeight : 80;
            const targetPosition = target.offsetTop - headerHeight;
            
            window.scrollTo({
                top: targetPosition,
                behavior: 'smooth'
            });
            
            if (window.innerWidth <= 768 && state.isMobileMenuOpen) {
                setTimeout(() => {
                    state.isMobileMenuOpen = false;
                    dom.navLinks?.classList.remove('active');
                    dom.mobileMenuBtn.innerHTML = '<i class="fas fa-bars"></i>';
                    document.body.style.overflow = 'auto';
                }, 300);
            }
        }
    }

    // ========================
    // Scroll Animations
    // ========================
    class ScrollAnimations {
        constructor() {
            this.init();
        }

        init() {
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -100px 0px'
            };
            
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                        observer.unobserve(entry.target);
                    }
                });
            }, observerOptions);
            
            utils.$$('.card, .step, .contact-item, .curriculum-info, .objective-item').forEach(el => {
                if (el) {
                    el.style.opacity = '0';
                    el.style.transform = 'translateY(30px)';
                    el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
                    observer.observe(el);
                }
            });
        }
    }

    // ========================
    // Form Handler
    // ========================
    class FormHandler {
        constructor(form, endpoint, successMessage, options = {}) {
            this.form = form;
            this.endpoint = endpoint;
            this.successMessage = successMessage;
            this.options = {
                phoneFields: options.phoneFields || [],
                fieldMapping: options.fieldMapping || {},
                isContactForm: options.isContactForm || false
            };
            this.submitBtn = form.querySelector('[type="submit"]');
            this.init();
        }

        init() {
            this.setupRealTimeValidation();
            this.form.addEventListener('submit', (e) => this.handleSubmit(e));
        }

        setupRealTimeValidation() {
            const fields = this.form.querySelectorAll('input, textarea, select');
            fields.forEach(field => {
                field.addEventListener('blur', () => this.validateField(field));
                field.addEventListener('input', () => {
                    if (field.classList.contains('is-invalid')) {
                        this.validateField(field);
                    }
                });
            });
        }

        validateField(field) {
            const value = field.value.trim();
            const fieldName = field.name || field.id;
            let isValid = true;
            let errorMessage = '';

            if (field.hasAttribute('required') && !value) {
                isValid = false;
                errorMessage = 'This field is required';
            }

            if (field.type === 'email' && value && !utils.validateEmail(value)) {
                isValid = false;
                errorMessage = 'Please enter a valid email address';
            }

            const phoneFields = [...this.options.phoneFields, 'contactPhone', 'phone', 'motherPhone', 'fatherPhone', 'studentPhone'];
            if (phoneFields.includes(fieldName) && value && !utils.validatePhone(value)) {
                isValid = false;
                errorMessage = 'Please enter a valid phone number (10-15 digits)';
            }

            this.setFieldState(field, isValid ? 'valid' : 'invalid', errorMessage);
            return isValid;
        }

        setFieldState(field, state, message = '') {
            field.classList.remove('is-valid', 'is-invalid');
            
            const existingFeedback = field.parentNode.querySelector('.form-feedback');
            if (existingFeedback) existingFeedback.remove();
            
            if (state === 'valid') {
                field.classList.add('is-valid');
                utils.animateElement(field, 'pulse', 500);
            } else if (state === 'invalid') {
                field.classList.add('is-invalid');
                
                if (message) {
                    const feedback = document.createElement('div');
                    feedback.className = 'form-feedback invalid-feedback';
                    feedback.textContent = message;
                    field.parentNode.appendChild(feedback);
                    utils.animateElement(feedback, 'slideIn', 300);
                }
            }
        }

        validateForm() {
            let isValid = true;
            const requiredFields = this.form.querySelectorAll('[required]');
            
            requiredFields.forEach(field => {
                if (!this.validateField(field)) {
                    isValid = false;
                    if (isValid === false) {
                        const rect = field.getBoundingClientRect();
                        if (rect.top < 0 || rect.bottom > window.innerHeight) {
                            field.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                    }
                }
            });
            
            return isValid;
        }

        async handleSubmit(e) {
            e.preventDefault();
            if (state.isSubmitting) return;
            
            if (!this.validateForm()) {
                Toast.show('error', 'Validation Error', ERROR_MESSAGES.validation);
                return;
            }

            state.isSubmitting = true;
            this.setSubmitButtonState('processing');

            try {
                // Use different data extraction methods based on form type
                let data;
                if (this.options.isContactForm) {
                    data = utils.formDataToJson(this.form);
                } else {
                    data = utils.applicationFormDataToJson(this.form);
                }
                
                // Apply field mappings and phone normalization
                const processedData = {};
                
                for (let [key, value] of Object.entries(data)) {
                    const mappedKey = this.options.fieldMapping[key] || key;
                    
                    // Normalize phone numbers for specific fields
                    const phoneFields = ['motherPhone', 'fatherPhone', 'studentPhone', 'contactPhone'];
                    if (phoneFields.includes(key)) {
                        processedData[mappedKey] = utils.normalizePhone(value);
                    } else {
                        processedData[mappedKey] = value;
                    }
                }

                // Log request details for debugging
                // utils.logRequestHeaders(this.endpoint, processedData, {
                //     'Content-Type': 'application/json',
                //     'Accept': 'application/json'
                // });

                const result = await this.apiRequest(processedData);
                
                if (result.warnings && Object.keys(result.warnings).length > 0) {
                    this.displayServerWarnings(result.warnings);
                    const warnCount = Object.keys(result.warnings).length;
                    Toast.show('warning', 'Note', 
                        `There ${warnCount === 1 ? 'is 1 minor issue' : `are ${warnCount} minor issues`} with your submission.`);
                }

                if (this.form === dom.applicationForm) {
                    await this.handleApplicationSuccess(result);
                } else {
                    Toast.show('success', 'Success', this.successMessage);
                    this.form.reset();
                    this.clearValidationStates();
                }

                return result;
            } catch (error) {
                console.error('Submission error:', error);
                this.handleSubmissionError(error);
                return null;
            } finally {
                state.isSubmitting = false;
                if (this.form !== dom.applicationForm) {
                    this.restoreSubmitButton();
                }
            }
        }

        async apiRequest(data) {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), CONFIG.timeout);
            
            try {
                const response = await fetch(this.endpoint, {
                    method: 'POST',
                    signal: controller.signal,
                    body: JSON.stringify(data),
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                clearTimeout(timeoutId);
                
                let result;
                try {
                    result = await response.json();
                } catch (parseError) {
                    console.error('Failed to parse response:', parseError);
                    throw new Error('Invalid response from server');
                }
                
                if (!response.ok) {
                    const error = new Error(result.message || `HTTP ${response.status}`);
                    error.status = response.status;
                    error.body = result;
                    throw error;
                }

                const successFlag = result.success === true || result.status === 'success';
                if (!successFlag) {
                    const error = new Error(result.message || 'Submission failed');
                    error.body = result;
                    throw error;
                }

                return result;
            } catch (error) {
                clearTimeout(timeoutId);
                
                if (error.name === 'AbortError') {
                    throw new Error(ERROR_MESSAGES.timeout);
                } else if (error.name === 'TypeError') {
                    throw new Error(ERROR_MESSAGES.network);
                }
                throw error;
            }
        }

        async handleApplicationSuccess(result) {
            dom.applicationForm.style.display = 'none';
            dom.applicationForm.style.opacity = '0';
            
            if (dom.successMessage) {
                dom.successMessage.style.display = 'flex';
                dom.successMessage.style.opacity = '1';
                dom.successMessage.innerHTML = `
                    <div style="text-align: center; width: 100%; padding: 40px;">
                        <i class="fas fa-check-circle" style="font-size: 4rem; color: #10b981; margin-bottom: 20px;"></i>
                        <h3 style="color: #065f46; margin-bottom: 15px; font-size: 1.5rem;">Application Submitted Successfully!</h3>
                        <p style="color: #065f46; margin-bottom: 20px; line-height: 1.5;">${this.successMessage}</p>
                        ${result.application_id ? 
                            `<div style="background: rgba(16, 185, 129, 0.1); padding: 10px; border-radius: 6px; margin: 15px 0;">
                                <strong style="color: #065f46;">Application ID:</strong> 
                                <span style="color: #047857; font-weight: 600;">${result.application_id}</span>
                            </div>` : ''}
                        <p style="color: #666; font-size: 0.9rem; margin-bottom: 25px; line-height: 1.4;">
                            <i class="fas fa-info-circle" style="margin-right: 5px;"></i> 
                            We will contact you within 2-3 business days.
                        </p>
                        <button class="btn btn-primary" id="closeSuccessBtn" style="margin-top: 10px; padding: 12px 30px; font-size: 16px;">
                            <i class="fas fa-check"></i> Close
                        </button>
                    </div>
                `;
                
                await utils.animateElement(dom.successMessage, 'slideIn', 500);
                
                document.getElementById('closeSuccessBtn')?.addEventListener('click', () => {
                    Modal.close();
                });
                
                setTimeout(() => {
                    if (state.isModalOpen) {
                        Modal.close();
                    }
                }, 10000);
            }
            
            this.form.reset();
            this.clearValidationStates();
            Toast.show('success', 'Success', this.successMessage);
        }

        handleSubmissionError(error) {
            this.clearValidationStates();
            
            if (error.body?.errors) {
                this.displayServerErrors(error.body.errors);
            }
            
            if (error.body?.warnings) {
                this.displayServerWarnings(error.body.warnings);
            }

            this.setSubmitButtonState('failed');
            setTimeout(() => this.restoreSubmitButton(), 2000);
            
            const message = error.body?.message || error.message || ERROR_MESSAGES.server;
            Toast.show('error', 'Submission Error', message);
        }

        displayServerErrors(errors = {}) {
            Object.entries(errors).forEach(([key, msg]) => {
                let field = this.form.querySelector(`[name="${key}"]`) ||
                           this.form.querySelector(`#${key}`);
                
                if (field) {
                    this.setFieldState(field, 'invalid', Array.isArray(msg) ? msg.join('; ') : String(msg));
                    if (field) {
                        field.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
            });
        }

        displayServerWarnings(warnings = {}) {
            Object.entries(warnings).forEach(([key, msg]) => {
                let field = this.form.querySelector(`[name="${key}"]`) ||
                           this.form.querySelector(`#${key}`);
                
                if (field) {
                    const existing = field.parentNode.querySelector('.form-feedback.warning-feedback');
                    if (existing) existing.remove();

                    const feedback = document.createElement('div');
                    feedback.className = 'form-feedback warning-feedback';
                    feedback.textContent = Array.isArray(msg) ? msg.join('; ') : String(msg);
                    field.parentNode.appendChild(feedback);
                    utils.animateElement(feedback, 'slideIn', 300);
                }
            });
        }

        setSubmitButtonState(state) {
            if (!this.submitBtn) return;
            
            const states = {
                processing: {
                    html: '<i class="fas fa-spinner fa-spin"></i> Processing...',
                    disabled: true,
                    opacity: '0.7'
                },
                failed: {
                    html: '<i class="fas fa-exclamation-triangle"></i> Failed',
                    disabled: true,
                    opacity: '1',
                    bgColor: '#ef4444'
                }
            };

            if (!states[state]) return;
            
            if (!this.originalButtonState) {
                this.originalButtonState = {
                    html: this.submitBtn.innerHTML,
                    bgColor: this.submitBtn.style.backgroundColor
                };
            }

            this.submitBtn.innerHTML = states[state].html;
            this.submitBtn.disabled = states[state].disabled;
            this.submitBtn.style.opacity = states[state].opacity;
            if (states[state].bgColor) {
                this.submitBtn.style.backgroundColor = states[state].bgColor;
            }
        }

        restoreSubmitButton() {
            if (!this.submitBtn || !this.originalButtonState) return;
            
            this.submitBtn.innerHTML = this.originalButtonState.html;
            this.submitBtn.disabled = false;
            this.submitBtn.style.opacity = '1';
            this.submitBtn.style.backgroundColor = this.originalButtonState.bgColor;
            delete this.originalButtonState;
        }

        clearValidationStates() {
            const fields = this.form.querySelectorAll('.is-valid, .is-invalid');
            fields.forEach(field => field.classList.remove('is-valid', 'is-invalid'));
            
            const feedbacks = this.form.querySelectorAll('.form-feedback');
            feedbacks.forEach(feedback => feedback.remove());
        }
    }

    // ========================
    // Dynamic Styles
    // ========================
    const addDynamicStyles = () => {
        const style = document.createElement('style');
        style.textContent = `
            @keyframes pulse {
                0%, 100% { transform: scale(1); }
                50% { transform: scale(1.05); }
            }
            
            @keyframes slideDown {
                from { opacity: 0; transform: translateY(-20px); }
                to { opacity: 1; transform: translateY(0); }
            }
            
            @keyframes slideUp {
                from { opacity: 0; transform: translateY(30px); }
                to { opacity: 1; transform: translateY(0); }
            }
            
            @keyframes fadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }
            
            @keyframes slideIn {
                from { opacity: 0; transform: translateX(-20px); }
                to { opacity: 1; transform: translateX(0); }
            }
            
            @keyframes slideOut {
                from { opacity: 1; transform: translateX(0); }
                to { opacity: 0; transform: translateX(120px); }
            }
            
            @keyframes toastSlideIn {
                from { opacity: 0; transform: translateX(120px); }
                to { opacity: 1; transform: translateX(0); }
            }
            
            @keyframes spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }
            
            .is-valid {
                border-color: #6B9080 !important;
                background-color: rgba(107, 144, 128, 0.05) !important;
            }
            
            .is-invalid {
                border-color: #ef4444 !important;
                background-color: rgba(239, 68, 68, 0.05) !important;
            }
            
            .form-feedback {
                display: block;
                margin-top: 8px;
                font-size: 0.9rem;
                font-weight: 500;
                animation: slideIn 0.3s ease;
            }
            
            .invalid-feedback { color: #ef4444; }
            .warning-feedback { color: #f59e0b; }
            
            .nav-links.active { 
                animation: slideDown 0.3s ease; 
            }
            
            .modal { 
                animation: fadeIn 0.3s ease; 
                transition: opacity 0.3s ease;
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.7);
                z-index: 1000;
                justify-content: center;
                align-items: center;
                padding: 20px;
            }
            
            .modal-content { 
                animation: slideUp 0.4s cubic-bezier(0.4, 0, 0.2, 1); 
                transition: all 0.3s ease;
                background: white;
                border-radius: 12px;
                max-width: 600px;
                width: 100%;
                max-height: 90vh;
                overflow-y: auto;
            }
            
            .success-message {
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                min-height: 400px;
                text-align: center;
                padding: 40px;
                animation: slideIn 0.5s ease;
                background: linear-gradient(135deg, #d1fae5, #a7f3d0);
                color: #065f46;
                border-radius: 12px;
                margin: 0;
                border: 1px solid #10b981;
            }
            
            .fa-spinner { 
                animation: spin 1s linear infinite; 
            }
            
            .mobile-menu-btn {
                display: none;
                background: none;
                border: none;
                font-size: 24px;
                color: #333;
                cursor: pointer;
                padding: 10px;
            }
            
            @media (max-width: 768px) {
                .mobile-menu-btn { 
                    display: flex !important;
                    align-items: center;
                    justify-content: center;
                }
                
                .btn-apply { 
                    display: none !important; 
                }
                
                .nav-links {
                    position: fixed;
                    top: 70px;
                    left: 0;
                    width: 100%;
                    background: white;
                    flex-direction: column;
                    align-items: center;
                    padding: 20px;
                    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
                    transform: translateY(-100%);
                    opacity: 0;
                    visibility: hidden;
                    transition: all 0.3s ease;
                    z-index: 999;
                    gap: 0;
                }
                
                .nav-links.active {
                    transform: translateY(0);
                    opacity: 1;
                    visibility: visible;
                }
                
                .nav-links li { 
                    width: 100%; 
                    text-align: center; 
                    margin: 0; 
                }
                
                .nav-links a {
                    display: block;
                    padding: 15px;
                    border-bottom: 1px solid #e5e7eb;
                    font-size: 1.1rem;
                    color: #222;
                    text-decoration: none;
                }
                
                .apply-btn { 
                    animation: pulse 2s infinite; 
                }
            }
            
            @media (min-width: 769px) {
                .mobile-menu-btn { 
                    display: none !important; 
                }
                
                .btn-apply { 
                    display: inline-flex !important; 
                }
                
                .nav-links { 
                    display: flex !important; 
                }
            }
            
            .touch-action { 
                touch-action: manipulation; 
            }
            
            .tab-btn {
                transition: all 0.3s ease;
                cursor: pointer;
            }
            
            .tab-btn.active {
                background: #4A6FA5;
                color: white;
            }
            
            .tab-content {
                display: none;
                opacity: 0;
                transform: translateY(10px);
                transition: all 0.3s ease;
            }
            
            .tab-content.active {
                display: block;
                opacity: 1;
                transform: translateY(0);
            }
            
            /* Toast notification animations */
            .bm-toast {
                will-change: transform, opacity;
            }
            
            /* Custom styles for welcome toast */
            .bm-toast-welcome {
                background: #ffffff !important;
                border: 1px solid #e5e7eb !important;
                border-left: 4px solid #4b5563 !important;
            }
        `;
        document.head.appendChild(style);
        
        document.querySelectorAll('button, a, input, select, textarea').forEach(el => {
            el.classList.add('touch-action');
        });
    };

    // ========================
    // Application Initialization
    // ========================
    const init = () => {
        try {
            if (dom.applicationModal) {
                dom.applicationModal.style.transition = 'opacity 0.3s ease';
                dom.applicationModal.style.opacity = '0';
                dom.applicationModal.style.pointerEvents = 'none';
            }
            
            if (dom.successMessage) {
                dom.successMessage.style.transition = 'opacity 0.5s ease';
                dom.successMessage.style.display = 'none';
                dom.successMessage.style.opacity = '0';
                dom.successMessage.style.pointerEvents = 'none';
            }
            
            new MobileMenu();
            new HeaderScroll();
            new HeroSlider();
            new CurriculumTabs();
            new GallerySlider();
            new Announcement();
            Modal.init();
            new SmoothScrolling();
            new ScrollAnimations();
            
            if (dom.applicationForm) {
                new FormHandler(
                    dom.applicationForm,
                    CONFIG.endpoints.application,
                    'Your application has been submitted successfully! Our admissions team will review it and contact you within 2-3 business days.',
                    {
                        phoneFields: ['motherPhone', 'fatherPhone', 'studentPhone'],
                        fieldMapping: {},
                        isContactForm: false
                    }
                );
            }
            
            if (dom.contactForm) {
                new FormHandler(
                    dom.contactForm,
                    CONFIG.endpoints.contact,
                    'Your message has been sent successfully! We will respond to your inquiry within 24-48 hours.',
                    {
                        phoneFields: ['contactPhone'],
                        fieldMapping: {
                            'contactName': 'name',
                            'contactEmail': 'email',
                            'contactPhone': 'phone',
                            'contactSubject': 'subject',
                            'contactMessage': 'message'
                        },
                        isContactForm: true
                    }
                );
            }
            
            document.body.style.opacity = '0';
            document.body.style.transition = 'opacity 0.5s ease';
            
            setTimeout(() => {
                document.body.style.opacity = '1';
            }, 100);
            
        } catch (error) {
            console.error('Initialization error:', error);
            Toast.show('error', 'Initialization Error', 'Some features failed to load. Please refresh the page.');
        }
    };

    // ========================
    // Error Handling
    // ========================
    window.addEventListener('error', (event) => {
        console.error('JavaScript Error:', event.error);
        Toast.show('error', 'Script Error', 'An error occurred. Please refresh the page.');
    });

    window.addEventListener('unhandledrejection', (event) => {
        console.error('Unhandled Promise Rejection:', event.reason);
        Toast.show('error', 'Promise Error', 'An unexpected error occurred.');
    });

    // ========================
    // Start Application
    // ========================
    addDynamicStyles();
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();