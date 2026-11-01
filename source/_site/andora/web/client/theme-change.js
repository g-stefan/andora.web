/*!
// Andora
// SPDX-FileCopyrightText: 2026 Grigore Stefan <g_stefan@yahoo.com>
// SPDX-License-Identifier: CC0-1.0
*/

document.addEventListener("DOMContentLoaded", () => {
    const themeRadios = document.getElementsByName("theme-dropdown");
    // localStorage throws when the storage is blocked (privacy settings)
    let storedTheme = null;
    try {
        storedTheme = localStorage.getItem("theme");
    } catch (e) {
        storedTheme = null;
    }

    // Function to apply theme to document
    const applyTheme = (theme) => {
        if (theme === "default") {
            // Remove data-theme to revert to system/daisyUI default
            document.documentElement.removeAttribute("data-theme");
        } else {
            document.documentElement.setAttribute("data-theme", theme);            
        }
    };

    // 1. Restore state on page load
    if (storedTheme) {
        applyTheme(storedTheme);
        // Find the radio button corresponding to the stored theme
        const matchingRadio = Array.from(themeRadios).find(radio => radio.value === storedTheme);
        if (matchingRadio) {
            matchingRadio.checked = true;
        }
    }

    // 2. Listen for changes and save to localStorage
    themeRadios.forEach(radio => {
        radio.addEventListener("change", (e) => {
            const selectedTheme = e.target.value;
            try {
                localStorage.setItem("theme", selectedTheme);
            } catch (e) {
                // the theme is applied, only not remembered
            }
            applyTheme(selectedTheme);
        });
    });
});
