(function () {
    async function updateCredits() {
        try {
            const response = await fetch(hexcommandCredits.restUrl, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'X-WP-Nonce': hexcommandCredits.nonce
                }
            })

            if (!response.ok) {
                throw new Error('Unable to retrieve credits');
            }
            const data = await response.json();
            const credits = data.credits;
            const nav = document.querySelector('.nav-menu-secondary');

            if (!nav) {
                return;
            }
            const ul = nav.querySelector('ul');
            if (!ul) {
                return;
            }
            // Find existing credits span, or create it
            let creditsElement = nav.querySelector('.credits');
            if (!creditsElement) {
                creditsElement = document.createElement('span');
                creditsElement.className = 'credits';

                // Insert BEFORE the nav's <ul>
                ul.parentNode.insertBefore(creditsElement, ul);
            }

            // Update its content
            creditsElement.innerHTML = 'Crowns : ' + credits;
        } catch (error) {
            console.error('HexCommand credits :', error);
        }
    }

    // Initial value
    updateCredits();

    // Check every 10 seconds
    setInterval(updateCredits, 10000);
})();