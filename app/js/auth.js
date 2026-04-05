// Authentication Logic
window.DISABLE_SUPABASE_AUTH = true; // Set to true to bypass Supabase login

const auth = {
    async signUp(email, password) {
        if (window.DISABLE_SUPABASE_AUTH) {
            return { data: { user: { email } }, error: null };
        }
        const { data, error } = await window.supabaseClient.auth.signUp({
            email,
            password,
        });
        return { data, error };
    },

    async signIn(email, password) {
        if (window.DISABLE_SUPABASE_AUTH) {
            return { data: { user: { email } }, error: null };
        }
        const { data, error } = await window.supabaseClient.auth.signInWithPassword({
            email,
            password,
        });
        return { data, error };
    },

    async signOut() {
        if (window.DISABLE_SUPABASE_AUTH) {
            return { error: null };
        }
        const { error } = await window.supabaseClient.auth.signOut();
        return { error };
    },

    async checkSession() {
        if (window.DISABLE_SUPABASE_AUTH) {
            return { user: { email: 'guest@aipdf-renamer.local' } };
        }
        const { data: { session } } = await window.supabaseClient.auth.getSession();
        return session;
    },

    onAuthStateChange(callback) {
        if (window.DISABLE_SUPABASE_AUTH) {
            // Simulate initial session
            setTimeout(() => {
                callback('SIGNED_IN', { user: { email: 'guest@aipdf-renamer.local' } });
            }, 0);
            return;
        }
        window.supabaseClient.auth.onAuthStateChange((event, session) => {
            callback(event, session);
        });
    }
};

window.auth = auth;
