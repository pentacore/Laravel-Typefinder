import { Plugin } from 'vite';

interface TypefinderOptions {
    command?: string;
    buildCommand?: string | false;
    watch?: string[];
    debounceMs?: number;
    startupTimeoutMs?: number;
    killTimeoutMs?: number;
}
declare const typefinder: ({ command, buildCommand, watch, debounceMs, startupTimeoutMs, killTimeoutMs, }?: TypefinderOptions) => Plugin;

interface Category {
    enabled: boolean;
    paths: string[];
}
interface Categories {
    models: Category;
    enums: Category;
    requests: Category;
    resources: Category;
    inertia: Category;
    broadcasting: Category;
}
interface Handshake {
    type: 'ready';
    version: string;
    protocol: number;
    output_path: string;
    categories: Categories;
}
interface RegenRequest {
    type: 'regen';
    id: string;
    paths: string[];
}
interface RegenResponse {
    type: 'regen.done';
    id: string;
    duration_ms: number;
    changed: string[];
    warnings: string[];
    failed: Array<{
        path: string;
        message: string;
    }>;
}
interface RegenError {
    type: 'regen.error';
    id: string | null;
    message: string;
    trace?: string;
}

export { typefinder as default, typefinder };
export type { Categories, Category, Handshake, RegenError, RegenRequest, RegenResponse, TypefinderOptions };
