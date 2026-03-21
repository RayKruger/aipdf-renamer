// Supabase initialization (assuming script loaded via CDN)
const SUPABASE_URL = "https://jgwzhmbqpyozejfoqhmg.supabase.co";
const SUPABASE_ANON_KEY = "sb_publishable_z6HhLEDqqxsUoCa-QZOUDg_ujSZWTBf";

// Initialize the Supabase client
// Note: When using the CDN script, 'supabase' is the global object
const { createClient } = supabase;
const supabaseClient = createClient(SUPABASE_URL, SUPABASE_ANON_KEY);

// For ES modules or direct usage elsewhere
// export { supabaseClient }; 
// (Disabled export for now since using global scripts is easier for a simple static site)
window.supabaseClient = supabaseClient;
