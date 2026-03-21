// Authentication Logic
const auth = {
    async signUp(email, password) {
        const { data, error } = await window.supabaseClient.auth.signUp({
            email,
            password,
        });
        return { data, error };
    },

    async signIn(email, password) {
        const { data, error } = await window.supabaseClient.auth.signInWithPassword({
            email,
            password,
        });
        return { data, error };
    },

    async signOut() {
        const { error } = await window.supabaseClient.auth.signOut();
        return { error };
    },

    async checkSession() {
        const { data: { session } } = await window.supabaseClient.auth.getSession();
        return session;
    },

    onAuthStateChange(callback) {
        window.supabaseClient.auth.onAuthStateChange((event, session) => {
            callback(event, session);
        });
    }
};

window.auth = auth;
