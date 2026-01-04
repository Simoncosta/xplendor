export interface ISubscribers {
    id: number | undefined;
    name?: string;
    email: string;
    is_subscribed: boolean;
    subscribed_at: string;
    unsubscribed_at?: string;
}