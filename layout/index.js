        document.addEventListener('DOMContentLoaded', function() {
            lucide.createIcons();

            // Accordion toggle
            document.querySelectorAll('[data-accordion]').forEach(button => {
                button.addEventListener('click', function() {
                    const targetId = this.dataset.accordion;
                    const target = document.getElementById(targetId);
                    const chevron = this.querySelector('[data-lucide="chevron-down"]');

                    if (target.classList.contains('hidden')) {
                        target.classList.remove('hidden');
                        chevron.style.transform = 'rotate(180deg)';
                        this.classList.add('bg-muted');
                    } else {
                        target.classList.add('hidden');
                        chevron.style.transform = 'rotate(0deg)';
                        this.classList.remove('bg-muted');
                    }
                });
            });
        });

        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebar-overlay');

            if (sidebar.classList.contains('-translate-x-full')) {
                sidebar.classList.remove('-translate-x-full');
                overlay.classList.remove('hidden');
                document.body.style.overflow = 'hidden';
            } else {
                sidebar.classList.add('-translate-x-full');
                overlay.classList.add('hidden');
                document.body.style.overflow = '';
            }
        }

        function openAddModal() {
            const modal = document.getElementById('add-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            setTimeout(() => {
                modal.querySelector('div').classList.remove('scale-95');
                modal.querySelector('div').classList.add('scale-100');
            }, 10);
        }

        function closeAddModal() {
            const modal = document.getElementById('add-modal');
            modal.querySelector('div').classList.remove('scale-100');
            modal.querySelector('div').classList.add('scale-95');
            setTimeout(() => {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }, 200);
        }

        function saveNewMember() {
            // Demo functionality
            closeAddModal();

            // Show simple toast/notification logic here if needed
            const btn = document.querySelector('button[onclick="openAddModal()"]');
            const originalContent = btn.innerHTML;
            btn.innerHTML = '<i data-lucide="check" class="size-5"></i><span>Tersimpan!</span>';
            btn.classList.replace('bg-primary', 'bg-success');
            lucide.createIcons();

            setTimeout(() => {
                btn.innerHTML = originalContent;
                btn.classList.replace('bg-success', 'bg-primary');
                lucide.createIcons();
            }, 2000);
        }

        function manageMember(id) {
            console.log('Managing member ID:', id);
            // Implementation for manage/detail view
        }

        // Search Modal Logic
        function openSearchModal() {
            const modal = document.getElementById('search-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.getElementById('modal-search-input').focus();
        }

        function closeSearchModal() {
            const modal = document.getElementById('search-modal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        document.getElementById('search-modal').addEventListener('click', function(e) {
            if (e.target === this) closeSearchModal();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAddModal();
                closeSearchModal();
            }
            if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
                e.preventDefault();
                openSearchModal();
            }
        });
