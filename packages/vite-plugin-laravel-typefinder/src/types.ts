export interface Category {
    enabled: boolean;
    paths: string[];
}

export interface Categories {
    models: Category;
    enums: Category;
    requests: Category;
    resources: Category;
    inertia: Category;
    broadcasting: Category;
}

export interface Handshake {
    type: 'ready';
    version: string;
    protocol: number;
    output_path: string;
    categories: Categories;
}

export interface RegenRequest {
    type: 'regen';
    id: string;
    paths: string[];
}

export interface RegenResponse {
    type: 'regen.done';
    id: string;
    duration_ms: number;
    changed: string[];
    warnings: string[];
    failed: Array<{ path: string; message: string }>;
}

export interface RegenError {
    type: 'regen.error';
    id: string | null;
    message: string;
    trace?: string;
}

export type IncomingMessage = Handshake | RegenResponse | RegenError;
