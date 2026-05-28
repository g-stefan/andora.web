/*!
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: CC0-1.0
*/

document.addEventListener("DOMContentLoaded", () => {
    const sidebar = document.getElementById("sidebar");
    const toggleBtn = document.getElementById("sidebar-toggle");
    const overlay = document.getElementById("mobile-overlay");

    function toggleSidebar() {
        // Check if we are in desktop or mobile view
        if (window.innerWidth >= 768) {
            // Desktop behavior: Collapse margin
            sidebar.classList.toggle("sidebar-collapsed");
        } else {
            // Mobile behavior: Slide in and show overlay
            sidebar.classList.toggle("sidebar-open");
            overlay.classList.toggle("hidden");
        }
    }

    // Bind events
    toggleBtn.addEventListener("click", toggleSidebar);

    // Close sidebar when clicking outside on mobile
    overlay.addEventListener("click", () => {
        sidebar.classList.remove("sidebar-open");
        overlay.classList.add("hidden");
    });

    // Reset layout states when window resizes between breakpoints
    window.addEventListener("resize", () => {
        if (window.innerWidth >= 768) {
            // Reset mobile states when moving to desktop
            sidebar.classList.remove("sidebar-open");
            overlay.classList.add("hidden");
        } else {
            // Reset desktop states when moving to mobile
            sidebar.classList.remove("sidebar-collapsed");
        }
    });
});
