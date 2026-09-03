export type UserRole = "owner" | "attendant";
export type PhoneCondition = "new" | "used";
export type TxType = "sale" | "swap" | "repair";
export type PaymentMethod = "cash" | "mobile_money" | "card" | "bank_transfer" | "other";
export type ItemDirection = "out" | "in";
export type AdjustmentType = "restock" | "correction";

export type Json =
  | string
  | number
  | boolean
  | null
  | { [key: string]: Json | undefined }
  | Json[];

export type Database = {
  public: {
    Tables: {
      shops: {
        Row: {
          id: string;
          name: string;
          location: string | null;
          phone: string | null;
          created_at: string;
        };
        Insert: {
          id?: string;
          name: string;
          location?: string | null;
          phone?: string | null;
          created_at?: string;
        };
        Update: {
          id?: string;
          name?: string;
          location?: string | null;
          phone?: string | null;
          created_at?: string;
        };
        Relationships: [];
      };
      users: {
        Row: {
          id: string;
          name: string;
          role: UserRole;
          shop_id: string | null;
          can_edit_stock: boolean;
          active: boolean;
          deactivated_at: string | null;
          deactivated_by: string | null;
          created_at: string;
        };
        Insert: {
          id: string;
          name?: string;
          role?: UserRole;
          shop_id?: string | null;
          can_edit_stock?: boolean;
          active?: boolean;
          deactivated_at?: string | null;
          deactivated_by?: string | null;
          created_at?: string;
        };
        Update: {
          id?: string;
          name?: string;
          role?: UserRole;
          shop_id?: string | null;
          can_edit_stock?: boolean;
          active?: boolean;
          deactivated_at?: string | null;
          deactivated_by?: string | null;
          created_at?: string;
        };
        Relationships: [
          {
            foreignKeyName: "users_shop_id_fkey";
            columns: ["shop_id"];
            isOneToOne: false;
            referencedRelation: "shops";
            referencedColumns: ["id"];
          },
        ];
      };
      phone_models: {
        Row: {
          id: string;
          shop_id: string;
          model_name: string;
          condition: PhoneCondition;
          cost_price: number | null;
          sale_price: number | null;
          opening_stock: number;
          bought_in: number;
          available: number;
          low_stock_threshold: number;
          created_at: string;
        };
        Insert: {
          id?: string;
          shop_id: string;
          model_name: string;
          condition?: PhoneCondition;
          cost_price?: number | null;
          sale_price?: number | null;
          opening_stock?: number;
          bought_in?: number;
          available?: number;
          low_stock_threshold?: number;
          created_at?: string;
        };
        Update: {
          id?: string;
          shop_id?: string;
          model_name?: string;
          condition?: PhoneCondition;
          cost_price?: number | null;
          sale_price?: number | null;
          opening_stock?: number;
          bought_in?: number;
          available?: number;
          low_stock_threshold?: number;
          created_at?: string;
        };
        Relationships: [
          {
            foreignKeyName: "phone_models_shop_id_fkey";
            columns: ["shop_id"];
            isOneToOne: false;
            referencedRelation: "shops";
            referencedColumns: ["id"];
          },
        ];
      };
      transactions: {
        Row: {
          id: string;
          shop_id: string;
          staff_id: string;
          customer_name: string | null;
          customer_phone: string | null;
          type: TxType;
          payment_method: PaymentMethod;
          amount: number;
          date: string;
          created_at: string;
          idempotency_key: string | null;
          status: "completed" | "pending_review" | "voided" | "rejected";
          review_reason: string | null;
          listed_amount: number | null;
          discount_reason: string | null;
          payment_reference: string | null;
          reviewed_by: string | null;
          reviewed_at: string | null;
          voided_by: string | null;
          voided_at: string | null;
          void_reason: string | null;
        };
        Insert: {
          id?: string;
          shop_id: string;
          staff_id: string;
          customer_name?: string | null;
          customer_phone?: string | null;
          type: TxType;
          payment_method: PaymentMethod;
          amount?: number;
          date?: string;
          created_at?: string;
          idempotency_key?: string | null;
          status?: "completed" | "pending_review" | "voided" | "rejected";
          review_reason?: string | null;
          listed_amount?: number | null;
          discount_reason?: string | null;
          payment_reference?: string | null;
          reviewed_by?: string | null;
          reviewed_at?: string | null;
          voided_by?: string | null;
          voided_at?: string | null;
          void_reason?: string | null;
        };
        Update: {
          id?: string;
          shop_id?: string;
          staff_id?: string;
          customer_name?: string | null;
          customer_phone?: string | null;
          type?: TxType;
          payment_method?: PaymentMethod;
          amount?: number;
          date?: string;
          created_at?: string;
          idempotency_key?: string | null;
          status?: "completed" | "pending_review" | "voided" | "rejected";
          review_reason?: string | null;
          listed_amount?: number | null;
          discount_reason?: string | null;
          payment_reference?: string | null;
          reviewed_by?: string | null;
          reviewed_at?: string | null;
          voided_by?: string | null;
          voided_at?: string | null;
          void_reason?: string | null;
        };
        Relationships: [
          {
            foreignKeyName: "transactions_shop_id_fkey";
            columns: ["shop_id"];
            isOneToOne: false;
            referencedRelation: "shops";
            referencedColumns: ["id"];
          },
          {
            foreignKeyName: "transactions_staff_id_fkey";
            columns: ["staff_id"];
            isOneToOne: false;
            referencedRelation: "users";
            referencedColumns: ["id"];
          },
        ];
      };
      transaction_items: {
        Row: {
          id: string;
          transaction_id: string;
          phone_model_id: string;
          direction: ItemDirection;
          qty: number;
        };
        Insert: {
          id?: string;
          transaction_id: string;
          phone_model_id: string;
          direction: ItemDirection;
          qty?: number;
        };
        Update: {
          id?: string;
          transaction_id?: string;
          phone_model_id?: string;
          direction?: ItemDirection;
          qty?: number;
        };
        Relationships: [
          {
            foreignKeyName: "transaction_items_transaction_id_fkey";
            columns: ["transaction_id"];
            isOneToOne: false;
            referencedRelation: "transactions";
            referencedColumns: ["id"];
          },
          {
            foreignKeyName: "transaction_items_phone_model_id_fkey";
            columns: ["phone_model_id"];
            isOneToOne: false;
            referencedRelation: "phone_models";
            referencedColumns: ["id"];
          },
        ];
      };
      stock_adjustments: {
        Row: {
          id: string;
          shop_id: string;
          phone_model_id: string;
          staff_id: string;
          type: AdjustmentType;
          delta: number;
          reason: string | null;
          date: string;
        };
        Insert: {
          id?: string;
          shop_id: string;
          phone_model_id: string;
          staff_id: string;
          type?: AdjustmentType;
          delta: number;
          reason?: string | null;
          date?: string;
        };
        Update: {
          id?: string;
          shop_id?: string;
          phone_model_id?: string;
          staff_id?: string;
          type?: AdjustmentType;
          delta?: number;
          reason?: string | null;
          date?: string;
        };
        Relationships: [
          {
            foreignKeyName: "stock_adjustments_shop_id_fkey";
            columns: ["shop_id"];
            isOneToOne: false;
            referencedRelation: "shops";
            referencedColumns: ["id"];
          },
          {
            foreignKeyName: "stock_adjustments_phone_model_id_fkey";
            columns: ["phone_model_id"];
            isOneToOne: false;
            referencedRelation: "phone_models";
            referencedColumns: ["id"];
          },
          {
            foreignKeyName: "stock_adjustments_staff_id_fkey";
            columns: ["staff_id"];
            isOneToOne: false;
            referencedRelation: "users";
            referencedColumns: ["id"];
          },
        ];
      };
      swapped_phones: {
        Row: {
          id: string;
          shop_id: string;
          transaction_id: string | null;
          staff_id: string | null;
          model_name: string;
          condition: PhoneCondition;
          customer_name: string | null;
          customer_phone: string | null;
          status: "in_stock" | "sold" | "returned";
          notes: string | null;
          created_at: string;
        };
        Insert: {
          id?: string;
          shop_id: string;
          transaction_id?: string | null;
          staff_id?: string | null;
          model_name: string;
          condition?: PhoneCondition;
          customer_name?: string | null;
          customer_phone?: string | null;
          status?: "in_stock" | "sold" | "returned";
          notes?: string | null;
          created_at?: string;
        };
        Update: {
          id?: string;
          shop_id?: string;
          transaction_id?: string | null;
          staff_id?: string | null;
          model_name?: string;
          condition?: PhoneCondition;
          customer_name?: string | null;
          customer_phone?: string | null;
          status?: "in_stock" | "sold" | "returned";
          notes?: string | null;
          created_at?: string;
        };
        Relationships: [
          {
            foreignKeyName: "swapped_phones_shop_id_fkey";
            columns: ["shop_id"];
            isOneToOne: false;
            referencedRelation: "shops";
            referencedColumns: ["id"];
          },
          {
            foreignKeyName: "swapped_phones_transaction_id_fkey";
            columns: ["transaction_id"];
            isOneToOne: false;
            referencedRelation: "transactions";
            referencedColumns: ["id"];
          },
          {
            foreignKeyName: "swapped_phones_staff_id_fkey";
            columns: ["staff_id"];
            isOneToOne: false;
            referencedRelation: "users";
            referencedColumns: ["id"];
          },
        ];
      };
      login_logs: {
        Row: {
          id: string;
          user_id: string;
          email: string | null;
          name: string | null;
          ip: string | null;
          user_agent: string | null;
          device: string | null;
          created_at: string;
        };
        Insert: {
          id?: string;
          user_id: string;
          email?: string | null;
          name?: string | null;
          ip?: string | null;
          user_agent?: string | null;
          device?: string | null;
          created_at?: string;
        };
        Update: {
          id?: string;
          user_id?: string;
          email?: string | null;
          name?: string | null;
          ip?: string | null;
          user_agent?: string | null;
          device?: string | null;
          created_at?: string;
        };
        Relationships: [
          {
            foreignKeyName: "login_logs_user_id_fkey";
            columns: ["user_id"];
            isOneToOne: false;
            referencedRelation: "users";
            referencedColumns: ["id"];
          },
        ];
      };
      stock_logs: {
        Row: {
          id: string;
          shop_id: string;
          phone_model_id: string | null;
          staff_id: string;
          action: string;
          model_name: string | null;
          condition: string | null;
          details: Json | null;
          created_at: string;
        };
        Insert: {
          id?: string;
          shop_id: string;
          phone_model_id?: string | null;
          staff_id: string;
          action: string;
          model_name?: string | null;
          condition?: string | null;
          details?: Json | null;
          created_at?: string;
        };
        Update: {
          id?: string;
          shop_id?: string;
          phone_model_id?: string | null;
          staff_id?: string;
          action?: string;
          model_name?: string | null;
          condition?: string | null;
          details?: Json | null;
          created_at?: string;
        };
        Relationships: [
          {
            foreignKeyName: "stock_logs_shop_id_fkey";
            columns: ["shop_id"];
            isOneToOne: false;
            referencedRelation: "shops";
            referencedColumns: ["id"];
          },
          {
            foreignKeyName: "stock_logs_phone_model_id_fkey";
            columns: ["phone_model_id"];
            isOneToOne: false;
            referencedRelation: "phone_models";
            referencedColumns: ["id"];
          },
          {
            foreignKeyName: "stock_logs_staff_id_fkey";
            columns: ["staff_id"];
            isOneToOne: false;
            referencedRelation: "users";
            referencedColumns: ["id"];
          },
        ];
      };
      transaction_events: {
        Row: {
          id: string;
          transaction_id: string;
          actor_id: string;
          action: "created" | "approved" | "rejected" | "voided";
          details: Json | null;
          created_at: string;
        };
        Insert: {
          id?: string;
          transaction_id: string;
          actor_id: string;
          action: "created" | "approved" | "rejected" | "voided";
          details?: Json | null;
          created_at?: string;
        };
        Update: {
          id?: string;
          transaction_id?: string;
          actor_id?: string;
          action?: "created" | "approved" | "rejected" | "voided";
          details?: Json | null;
          created_at?: string;
        };
        Relationships: [];
      };
      daily_closes: {
        Row: {
          id: string;
          shop_id: string;
          close_date: string;
          status: "open" | "locked";
          expected_cash: number;
          expected_mobile_money: number;
          expected_other: number;
          counted_cash: number | null;
          counted_mobile_money: number | null;
          counted_other: number | null;
          notes: string | null;
          submitted_by: string;
          submitted_at: string;
          locked_by: string | null;
          locked_at: string | null;
          created_at: string;
        };
        Insert: {
          id?: string;
          shop_id: string;
          close_date: string;
          status?: "open" | "locked";
          expected_cash?: number;
          expected_mobile_money?: number;
          expected_other?: number;
          counted_cash?: number | null;
          counted_mobile_money?: number | null;
          counted_other?: number | null;
          notes?: string | null;
          submitted_by: string;
          submitted_at?: string;
          locked_by?: string | null;
          locked_at?: string | null;
          created_at?: string;
        };
        Update: {
          id?: string;
          shop_id?: string;
          close_date?: string;
          status?: "open" | "locked";
          expected_cash?: number;
          expected_mobile_money?: number;
          expected_other?: number;
          counted_cash?: number | null;
          counted_mobile_money?: number | null;
          counted_other?: number | null;
          notes?: string | null;
          submitted_by?: string;
          submitted_at?: string;
          locked_by?: string | null;
          locked_at?: string | null;
          created_at?: string;
        };
        Relationships: [];
      };
      stock_counts: {
        Row: {
          id: string;
          shop_id: string;
          count_date: string;
          status: "submitted" | "approved" | "applied";
          submitted_by: string;
          approved_by: string | null;
          notes: string | null;
          created_at: string;
        };
        Insert: {
          id?: string;
          shop_id: string;
          count_date: string;
          status?: "submitted" | "approved" | "applied";
          submitted_by: string;
          approved_by?: string | null;
          notes?: string | null;
          created_at?: string;
        };
        Update: {
          id?: string;
          shop_id?: string;
          count_date?: string;
          status?: "submitted" | "approved" | "applied";
          submitted_by?: string;
          approved_by?: string | null;
          notes?: string | null;
          created_at?: string;
        };
        Relationships: [];
      };
      stock_count_items: {
        Row: {
          id: string;
          count_id: string;
          phone_model_id: string;
          expected_qty: number;
          counted_qty: number;
        };
        Insert: {
          id?: string;
          count_id: string;
          phone_model_id: string;
          expected_qty: number;
          counted_qty: number;
        };
        Update: {
          id?: string;
          count_id?: string;
          phone_model_id?: string;
          expected_qty?: number;
          counted_qty?: number;
        };
        Relationships: [];
      };
      stock_requests: {
        Row: {
          id: string;
          shop_id: string;
          staff_id: string;
          type: "create_model" | "adjust_stock";
          status: "pending" | "approved" | "rejected";
          model_name: string | null;
          condition: PhoneCondition | null;
          cost_price: number | null;
          sale_price: number | null;
          low_stock_threshold: number | null;
          opening_stock: number | null;
          phone_model_id: string | null;
          delta: number | null;
          reason: string | null;
          created_at: string;
          decided_at: string | null;
          decided_by: string | null;
          error_note: string | null;
        };
        Insert: {
          id?: string;
          shop_id: string;
          staff_id: string;
          type: "create_model" | "adjust_stock";
          status?: "pending" | "approved" | "rejected";
          model_name?: string | null;
          condition?: PhoneCondition | null;
          cost_price?: number | null;
          sale_price?: number | null;
          low_stock_threshold?: number | null;
          opening_stock?: number | null;
          phone_model_id?: string | null;
          delta?: number | null;
          reason?: string | null;
          created_at?: string;
          decided_at?: string | null;
          decided_by?: string | null;
          error_note?: string | null;
        };
        Update: {
          id?: string;
          shop_id?: string;
          staff_id?: string;
          type?: "create_model" | "adjust_stock";
          status?: "pending" | "approved" | "rejected";
          model_name?: string | null;
          condition?: PhoneCondition | null;
          cost_price?: number | null;
          sale_price?: number | null;
          low_stock_threshold?: number | null;
          opening_stock?: number | null;
          phone_model_id?: string | null;
          delta?: number | null;
          reason?: string | null;
          created_at?: string;
          decided_at?: string | null;
          decided_by?: string | null;
          error_note?: string | null;
        };
        Relationships: [
          {
            foreignKeyName: "stock_requests_shop_id_fkey";
            columns: ["shop_id"];
            isOneToOne: false;
            referencedRelation: "shops";
            referencedColumns: ["id"];
          },
          {
            foreignKeyName: "stock_requests_staff_id_fkey";
            columns: ["staff_id"];
            isOneToOne: false;
            referencedRelation: "users";
            referencedColumns: ["id"];
          },
          {
            foreignKeyName: "stock_requests_phone_model_id_fkey";
            columns: ["phone_model_id"];
            isOneToOne: false;
            referencedRelation: "phone_models";
            referencedColumns: ["id"];
          },
        ];
      };
    };
    Views: Record<string, never>;
    Functions: {
      record_transaction: {
        Args: {
          p_shop_id: string;
          p_customer_name?: string | null;
          p_customer_phone?: string | null;
          p_type: TxType;
          p_payment_method: PaymentMethod;
          p_amount: number;
          p_date?: string;
          p_out_items?: { phone_model_id: string; qty: number }[];
          p_in_items?: Record<string, unknown>[];
          p_idempotency_key?: string | null;
          p_swap_in?: Record<string, unknown>[];
          p_discount_reason?: string | null;
          p_payment_reference?: string | null;
        };
        Returns: string;
      };
      review_transaction: {
        Args: { p_transaction_id: string; p_decision: string; p_reason?: string | null };
        Returns: undefined;
      };
      void_transaction: {
        Args: { p_transaction_id: string; p_reason: string };
        Returns: undefined;
      };
      update_swapped_phone_status: {
        Args: { p_id: string; p_status: string };
        Returns: undefined;
      };
      submit_daily_close: {
        Args: {
          p_shop_id: string;
          p_close_date: string;
          p_counted_cash: number;
          p_counted_mobile_money: number;
          p_counted_other?: number;
          p_notes?: string | null;
        };
        Returns: string;
      };
      lock_daily_close: {
        Args: { p_close_id: string };
        Returns: undefined;
      };
      submit_stock_count: {
        Args: { p_shop_id: string; p_count_date: string; p_items: Record<string, unknown>[]; p_notes?: string | null };
        Returns: string;
      };
      approve_stock_count: {
        Args: { p_count_id: string };
        Returns: undefined;
      };
      apply_stock_count_correction: {
        Args: { p_count_id: string; p_reason: string };
        Returns: number;
      };
      delete_transaction: {
        Args: { p_transaction_id: string };
        Returns: undefined;
      };
      adjust_stock: {
        Args: {
          p_shop_id: string;
          p_phone_model_id: string;
          p_delta: number;
          p_type?: AdjustmentType;
          p_reason?: string | null;
        };
        Returns: string;
      };
      bulk_adjust_stock: {
        Args: {
          p_shop_id: string;
          p_items: { phone_model_id: string; target_qty: number }[];
          p_reason?: string | null;
        };
        Returns: number;
      };
      current_user_profile: {
        Args: Record<PropertyKey, never>;
        Returns: {
          id: string;
          role: UserRole;
          shop_id: string | null;
        }[];
      };
      current_user_can_edit_stock: {
        Args: Record<PropertyKey, never>;
        Returns: boolean;
      };
      restore_backup: {
        Args: { p_data: unknown };
        Returns: {
          restored?: boolean;
          transactions?: number;
          stock_repaired?: number;
        } | null;
      };
      approve_stock_request: {
        Args: { p_request_id: string };
        Returns: undefined;
      };
      reject_stock_request: {
        Args: { p_request_id: string };
        Returns: undefined;
      };
      approve_all_stock_requests: {
        Args: { p_shop_id?: string | null };
        Returns: { approved: number; failed: number }[];
      };
    };
    Enums: {
      user_role: UserRole;
      phone_condition: PhoneCondition;
      tx_type: TxType;
      payment_method: PaymentMethod;
      item_direction: ItemDirection;
      adjustment_type: AdjustmentType;
    };
    CompositeTypes: Record<string, never>;
  };
};
